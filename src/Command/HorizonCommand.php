<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Saifulferoz\SymfonyHorizon\Supervisor\Supervisor;
use Saifulferoz\SymfonyHorizon\Supervisor\WorkerPool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Master supervisor. Spawns and monitors messenger:consume worker processes
 * for every configured supervisor block, autoscaling them on queue depth.
 */
#[AsCommand(name: 'horizon', description: 'Start the Horizon master supervisor for Symfony Messenger workers')]
final class HorizonCommand extends Command implements SignalableCommandInterface
{
    private const TICK_SECONDS = 1;

    private bool $shouldTerminate = false;
    /**
     * Version of the last control command applied by THIS instance. Commands
     * are broadcast state, so each instance de-duplicates independently and
     * every instance reacts to a pause/continue/terminate.
     */
    private ?int $lastCommandVersion = null;

    /**
     * @param array<string, array<string, mixed>> $supervisorsConfig
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly QueueDepthProvider $queueDepths,
        private readonly AutoScaler $autoScaler,
        private readonly array $supervisorsConfig,
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly ?string $phpBinary = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('supervisor', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only start the given supervisor block(s)')
            ->setHelp(<<<'HELP'
                Reads supervisor blocks from the symfony_horizon.supervisors config, spawns
                <info>messenger:consume</info> child processes for each and keeps them alive,
                scaling between min_processes and max_processes when balance is "auto".

                Control a running instance from another shell:
                  <info>horizon:pause</info>      stop consuming (workers shut down gracefully)
                  <info>horizon:continue</info>   resume consuming
                  <info>horizon:terminate</info>  graceful shutdown (finishes in-flight messages)
                HELP);
    }

    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldTerminate = true;

        return false; // keep running; the loop performs the graceful shutdown
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->supervisorsConfig;

        $only = $input->getOption('supervisor');
        if ($only !== []) {
            $config = array_intersect_key($config, array_flip($only));
        }

        if ($config === []) {
            if ($only !== []) {
                $output->writeln(sprintf(
                    '<error>No supervisor named %s.</error> Available: %s',
                    implode(', ', array_map(static fn (string $n): string => '"' . $n . '"', $only)),
                    $this->supervisorsConfig === [] ? '(none)' : implode(', ', array_keys($this->supervisorsConfig)),
                ));

                return Command::FAILURE;
            }

            $output->writeln('<error>Nothing to supervise.</error> No supervisors are configured, and no consumable Messenger transports were found to auto-discover.');
            $output->writeln('');
            $output->writeln('Either add a transport to <comment>config/packages/messenger.yaml</comment>, or configure one explicitly:');
            $output->writeln(<<<'YAML'

                symfony_horizon:
                    supervisors:
                        default:
                            transports: [async]
                            min_processes: 1
                            max_processes: 5
                            balance: auto
                YAML);
            $output->writeln('');
            $output->writeln('Run <info>bin/console horizon:status</info> to see what Horizon can see.');

            return Command::FAILURE;
        }

        $supervisors = [];
        foreach ($config as $name => $block) {
            $pool = new WorkerPool(
                $this->buildConsumeCommand($block),
                $output->isVerbose() ? $output : null,
            );
            $supervisors[] = new Supervisor($name, $block, $pool, $this->autoScaler, $this->queueDepths, $this->storage);
        }

        $output->writeln(sprintf('<info>Horizon started</info> (pid %d, env %s). Press Ctrl+C to stop gracefully.', getmypid() ?: 0, $this->environment));
        foreach ($config as $name => $block) {
            $output->writeln(sprintf(
                '  <comment>%s</comment> → %s (%s)',
                $name,
                implode(', ', $block['transports']),
                ($block['balance'] ?? 'off') === 'auto'
                    ? sprintf('auto-scaling %d-%d workers', $block['min_processes'] ?? 1, $block['max_processes'] ?? 1)
                    : sprintf('%d worker(s)', $block['min_processes'] ?? 1),
            ));
        }

        while (!$this->shouldTerminate) {
            foreach ($supervisors as $supervisor) {
                $supervisor->tick();
            }

            $this->applyPendingCommands($supervisors, $output);

            if (!$this->shouldTerminate) {
                sleep(self::TICK_SECONDS);
            }
        }

        $output->writeln('<info>Horizon terminating:</info> waiting for workers to finish in-flight messages...');
        foreach ($supervisors as $supervisor) {
            $supervisor->stop();
        }
        $output->writeln('<info>Horizon stopped.</info>');

        return Command::SUCCESS;
    }

    /**
     * @param list<Supervisor> $supervisors
     */
    private function applyPendingCommands(array $supervisors, OutputInterface $output): void
    {
        try {
            $state = $this->storage->readCommand();
        } catch (\Throwable $e) {
            // Losing control-plane reachability must not kill the supervisor;
            // it keeps running with its current state and retries next tick.
            $output->writeln(sprintf('<comment>[horizon]</comment> could not read control state: %s', $e->getMessage()));

            return;
        }

        if ($state === null) {
            return;
        }

        // On startup adopt the current version without replaying it, so a
        // freshly started instance does not re-run a long-past terminate.
        if ($this->lastCommandVersion === null) {
            $this->lastCommandVersion = $state['version'];

            // A standing pause must still be honoured by an instance that
            // starts while the cluster is paused.
            if ($state['command'] !== StorageInterface::CMD_PAUSE) {
                return;
            }
        } elseif ($state['version'] <= $this->lastCommandVersion) {
            return;
        }

        $this->lastCommandVersion = $state['version'];
        $output->writeln(sprintf('<comment>[horizon]</comment> applying command "%s" (v%d)', $state['command'], $state['version']));

        foreach ($supervisors as $supervisor) {
            match ($state['command']) {
                StorageInterface::CMD_PAUSE => $supervisor->pause(),
                StorageInterface::CMD_CONTINUE => $supervisor->resume(),
                StorageInterface::CMD_TERMINATE => $this->shouldTerminate = true,
                default => null,
            };
        }
    }

    /**
     * Resolves the PHP binary used to spawn workers.
     *
     * PHP_BINARY is not usable everywhere: under FrankenPHP's CLI it is an
     * empty string, and under other embedded SAPIs it can point at a server
     * binary rather than a PHP interpreter. Both would make Process() fail or
     * spawn the wrong program, so fall back to an explicit configuration value,
     * then to a sane default for the current runtime.
     *
     * @return list<string> binary plus any leading arguments
     */
    private function resolvePhpBinary(): array
    {
        if ($this->phpBinary !== null && $this->phpBinary !== '') {
            // Allow "frankenphp php-cli" or "/usr/bin/php -d opcache.enable=0".
            return array_values(array_filter(explode(' ', $this->phpBinary), static fn (string $p): bool => $p !== ''));
        }

        // FrankenPHP: PHP_BINARY is empty; its CLI is reached via `frankenphp php-cli`.
        if (\PHP_BINARY === '' || basename(\PHP_BINARY) === 'frankenphp') {
            $franken = getenv('FRANKENPHP_BINARY');
            if (!\is_string($franken) || $franken === '') {
                $franken = \PHP_BINARY !== '' ? \PHP_BINARY : 'frankenphp';
            }

            return [$franken, 'php-cli'];
        }

        return [\PHP_BINARY];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<string>
     */
    private function buildConsumeCommand(array $block): array
    {
        $console = $this->projectDir . '/bin/console';

        $command = [
            ...$this->resolvePhpBinary(),
            $console,
            'messenger:consume',
            ...$block['transports'],
            '--time-limit=' . $block['time_limit'],
            '--memory-limit=' . $block['memory_limit'] . 'M',
            '--env=' . $this->environment,
            '--no-interaction',
        ];

        foreach ($block['consume_options'] ?? [] as $option) {
            $command[] = $option;
        }

        return $command;
    }
}
