<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'horizon:pause', description: 'Pause all Horizon supervisors (workers shut down gracefully)')]
final class HorizonPauseCommand extends Command
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->pushCommand(StorageInterface::CMD_PAUSE);
        $output->writeln('<info>Pause signal sent to Horizon.</info>');

        return Command::SUCCESS;
    }
}
