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
     * @param array<string, array<string, mixed>> $supervisorsConfig
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly QueueDepthProvider $queueDepths,
        private readonly AutoScaler $autoScaler,
        private readonly array $supervisorsConfig,
        private readonly string $projectDir,
        private readonly string $environment,
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
            $output->writeln('<error>No supervisors configured.</error> Define at least one block under <comment>symfony_horizon.supervisors</comment>, e.g.:');
            $output->writeln(<<<'YAML'

                symfony_horizon:
                    supervisors:
                        default:
                            transports: [async]
                            min_processes: 1
                            max_processes: 5
                            balance: auto
                YAML);

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

        $output->writeln(sprintf(
            '<info>Horizon started</info> (pid %d, env %s) with supervisor%s: %s. Press Ctrl+C to stop gracefully.',
            getmypid() ?: 0,
            $this->environment,
            \count($supervisors) > 1 ? 's' : '',
            implode(', ', array_map(static fn (Supervisor $s) => $s->name(), $supervisors)),
        ));

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
        while (($command = $this->storage->popCommand()) !== null) {
            $output->writeln(sprintf('<comment>[horizon]</comment> received command "%s"', $command));

            foreach ($supervisors as $supervisor) {
                match ($command) {
                    StorageInterface::CMD_PAUSE => $supervisor->pause(),
                    StorageInterface::CMD_CONTINUE => $supervisor->resume(),
                    StorageInterface::CMD_TERMINATE => $this->shouldTerminate = true,
                    default => null,
                };
            }
        }
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
            \PHP_BINARY,
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
