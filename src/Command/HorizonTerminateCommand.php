<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'horizon:terminate', description: 'Gracefully terminate the Horizon master supervisor')]
final class HorizonTerminateCommand extends Command
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->pushCommand(StorageInterface::CMD_TERMINATE);
        $output->writeln('<info>Terminate signal sent to Horizon.</info>');

        return Command::SUCCESS;
    }
}
