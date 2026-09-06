<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rolls the per-minute metric buckets into hourly snapshots for long-range
 * charts. Schedule it like Laravel Horizon's snapshot command, e.g. cron:
 *
 *     * /5 * * * * php bin/console horizon:snapshot
 */
#[AsCommand(name: 'horizon:snapshot', description: 'Store a snapshot of the current queue metrics')]
final class SnapshotCommand extends Command
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Store a snapshot even if another instance already did for this interval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Cron typically fires on every host. Snapshots are cluster-wide data,
        // so without a lock each host would write a duplicate for the same
        // interval. The TTL is shorter than the recommended 5-minute schedule
        // so a crashed run cannot block the next one.
        if (!$input->getOption('force') && !$this->storage->acquireLock('snapshot', 240)) {
            $output->writeln('<comment>Snapshot skipped:</comment> another instance already stored one for this interval.');

            return Command::SUCCESS;
        }

        $this->storage->storeSnapshots();
        $output->writeln('<info>Metrics snapshot stored.</info>');

        return Command::SUCCESS;
    }
}
