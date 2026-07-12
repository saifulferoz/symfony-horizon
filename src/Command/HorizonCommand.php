<?php

namespace Saifulferoz\SymfonyHorizon\Command;

use Psr\Container\ContainerInterface;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\Autoscaler;
use Saifulferoz\SymfonyHorizon\Supervisor\MasterSupervisor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HorizonCommand extends Command
{
    protected static $defaultName = 'messenger:horizon';
    protected static $defaultDescription = 'Start the Messenger Horizon daemon';

    private array $config;
    private StorageInterface $storage;
    private Autoscaler $autoscaler;
    private ?ContainerInterface $receiverLocator;

    public function __construct(
        array $config,
        StorageInterface $storage,
        Autoscaler $autoscaler,
        ?ContainerInterface $receiverLocator = null
    ) {
        parent::__construct();

        $this->config = $config;
        $this->storage = $storage;
        $this->autoscaler = $autoscaler;
        $this->receiverLocator = $receiverLocator;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->setHelp('This command starts the background supervisors and queue workers.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting Symfony Horizon daemon...</info>');

        if (!extension_loaded('pcntl')) {
            $output->writeln('<comment>Warning: pcntl extension not loaded. Signal handling and async scaling will be limited.</comment>');
        }

        $master = new MasterSupervisor(
            $this->config,
            $this->storage,
            $this->receiverLocator,
            $this->autoscaler
        );

        try {
            $master->run();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Daemon crashed: %s</error>', $e->getMessage()));
            $master->terminate();
            return Command::FAILURE;
        }

        $output->writeln('<info>Daemon stopped gracefully.</info>');
        return Command::SUCCESS;
    }
}
