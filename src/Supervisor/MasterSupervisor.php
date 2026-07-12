<?php

namespace Saifulferoz\SymfonyHorizon\Supervisor;

use Psr\Container\ContainerInterface;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

class MasterSupervisor
{
    private array $config;
    private StorageInterface $storage;
    private ?ContainerInterface $receiverLocator;
    private Autoscaler $autoscaler;

    /** @var Supervisor[] */
    private array $supervisors = [];
    private bool $working = true;

    public function __construct(
        array $config,
        StorageInterface $storage,
        ?ContainerInterface $receiverLocator,
        Autoscaler $autoscaler
    ) {
        $this->config = $config;
        $this->storage = $storage;
        $this->receiverLocator = $receiverLocator;
        $this->autoscaler = $autoscaler;

        $this->initializeSupervisors();
        $this->registerSignalHandlers();
    }

    public function run(): void
    {
        while ($this->working) {
            $this->monitor();

            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            sleep(1);
        }
    }

    public function monitor(): void
    {
        foreach ($this->supervisors as $supervisor) {
            $supervisor->monitor();
        }

        // Master heartbeat
        $this->storage->recordSupervisorHeartbeat('master', [
            'name' => 'master',
            'pid' => (string) getmypid(),
            'host' => gethostname(),
            'status' => 'running',
            'last_heartbeat' => (string) microtime(true),
        ]);
    }

    public function terminate(): void
    {
        $this->working = false;

        foreach ($this->supervisors as $supervisor) {
            $supervisor->terminate();
        }

        $this->storage->removeSupervisor('master');
    }

    private function initializeSupervisors(): void
    {
        $supervisorsConfig = $this->config['supervisors'] ?? [];

        foreach ($supervisorsConfig as $name => $config) {
            $this->supervisors[$name] = new Supervisor(
                $name,
                $config,
                $this->storage,
                $this->receiverLocator,
                $this->autoscaler
            );
        }
    }

    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, [$this, 'terminate']);
            pcntl_signal(SIGINT, [$this, 'terminate']);
            pcntl_signal(SIGQUIT, [$this, 'terminate']);
        }
    }
}
