<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answers "is Horizon actually working?" in one command, and says exactly what
 * to do when it isn't. This is the first thing to run after installing.
 */
#[AsCommand(name: 'horizon:status', description: 'Show Horizon health: storage, supervisors, workers and queue depth')]
final class HorizonStatusCommand extends Command
{
    /**
     * @param array<string, array<string, mixed>> $supervisorsConfig
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly QueueDepthProvider $queueDepths,
        private readonly array $supervisorsConfig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Horizon status');

        // 1. Storage — everything else depends on it.
        try {
            $counters = $this->storage->getCounters();
            $io->success('Redis reachable.');
        } catch (\Throwable $e) {
            $io->error('Cannot reach Redis: ' . $e->getMessage());
            $io->writeln('Check <comment>symfony_horizon.redis.dsn</comment> (or <comment>client_service</comment>) in config/packages/symfony_horizon.yaml.');
            $io->newLine();
            $io->writeln('Workers keep consuming messages regardless — only metrics are affected.');

            return Command::FAILURE;
        }

        // 2. Configured supervisors and the transports they cover.
        if ($this->supervisorsConfig === []) {
            $io->warning('No supervisors configured and none could be auto-discovered.');
            $io->writeln('Define transports under <comment>symfony_horizon.supervisors</comment>, or check that your app has consumable Messenger transports.');
        } else {
            $rows = [];
            foreach ($this->supervisorsConfig as $name => $block) {
                $transports = $block['transports'] ?? [];
                $depths = $this->queueDepths->counts($transports);

                $rows[] = [
                    $name,
                    implode(', ', $transports),
                    ($block['balance'] ?? 'off') === 'auto'
                        ? \sprintf('auto %d-%d', $block['min_processes'] ?? 1, $block['max_processes'] ?? 1)
                        : \sprintf('fixed %d', $block['min_processes'] ?? 1),
                    implode('  ', array_map(
                        static fn (string $t, ?int $n): string => $t . ': ' . ($n ?? '?'),
                        array_keys($depths),
                        $depths,
                    )) ?: '—',
                ];
            }
            $io->section('Configured supervisors');
            $io->table(['Name', 'Transports', 'Processes', 'Pending'], $rows);
        }

        // 3. What is actually running right now.
        $supervisors = $this->storage->getSupervisors();
        $workers = $this->storage->getWorkers();

        $io->section('Running now');
        if ($supervisors === []) {
            $io->writeln('  Supervisors: <comment>none</comment> — start one with <info>bin/console horizon</info>');
        } else {
            $io->writeln(\sprintf('  Supervisors: <info>%d</info>', \count($supervisors)));
            foreach ($supervisors as $meta) {
                $io->writeln(\sprintf(
                    '    %s on %s — %s, %s worker process(es)',
                    $meta['name'] ?? '?',
                    $meta['host'] ?? '?',
                    $meta['status'] ?? '?',
                    $meta['processes'] ?? '0',
                ));
            }
        }

        $io->writeln(\sprintf('  Workers reporting: <info>%d</info>', \count($workers)));
        if ($workers === [] && $supervisors === []) {
            $io->writeln('    <comment>No workers.</comment> Nothing is consuming messages.');
        }

        // 4. Throughput so far.
        $io->section('Totals');
        $io->writeln(\sprintf('  Jobs processed : %s', number_format($counters['jobs_total'])));
        $io->writeln(\sprintf('  Failed         : %s', number_format($counters['failed_total'])));
        $io->writeln(\sprintf('  Awaiting review: %s failed job(s) in the dashboard', number_format($counters['failed_count'])));

        $control = $this->storage->readCommand();
        if (($control['command'] ?? null) === StorageInterface::CMD_PAUSE) {
            $io->newLine();
            $io->warning('Horizon is PAUSED cluster-wide. Resume with: bin/console horizon:continue');
        }

        return Command::SUCCESS;
    }
}
