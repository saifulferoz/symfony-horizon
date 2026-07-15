<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->storeSnapshots();
        $output->writeln('<info>Metrics snapshot stored.</info>');

        return Command::SUCCESS;
    }
}
