<?php

namespace Saifulferoz\SymfonyHorizon\Supervisor;

use Psr\Container\ContainerInterface;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Process\Process;

class Supervisor
{
    private string $name;
    private array $config;
    private StorageInterface $storage;
    private ?ContainerInterface $receiverLocator;
    private Autoscaler $autoscaler;

    /** @var Process[] */
    private array $processes = [];
    private string $consolePath;
    private int $targetProcesses;

    public function __construct(
        string $name,
        array $config,
        StorageInterface $storage,
        ?ContainerInterface $receiverLocator,
        Autoscaler $autoscaler
    ) {
        $this->name = $name;
        $this->config = $config;
        $this->storage = $storage;
        $this->receiverLocator = $receiverLocator;
        $this->autoscaler = $autoscaler;
        $this->targetProcesses = $config['processes'] ?? 3;

        $this->consolePath = realpath($_SERVER['argv'][0]) ?: 'bin/console';
    }

    public function monitor(): void
    {
        // 1. Prune finished processes
        foreach ($this->processes as $key => $process) {
            if (!$process->isRunning()) {
                unset($this->processes[$key]);
            }
        }

        // 2. Adjust target process count if auto-scaling is enabled
        if (($this->config['balance'] ?? 'simple') === 'auto') {
            $pendingCount = $this->getPendingCount();
            $this->targetProcesses = $this->autoscaler->scale(
                count($this->processes),
                $pendingCount,
                $this->config['processes'] ?? 1,
                $this->config['max_processes'] ?? 10
            );
        }

        // 3. Spawn workers to reach target
        while (count($this->processes) < $this->targetProcesses) {
            $this->spawnWorker();
        }

        // 4. Terminate workers if target decreased
        while (count($this->processes) > $this->targetProcesses) {
            $extraProcess = array_pop($this->processes);
            if ($extraProcess && $extraProcess->isRunning()) {
                $extraProcess->stop(5);
            }
        }

        // 5. Update supervisor heartbeat
        $this->storage->recordSupervisorHeartbeat($this->name, [
            'name' => $this->name,
            'pid' => (string) getmypid(),
            'target_workers' => (string) $this->targetProcesses,
            'active_workers' => (string) count($this->processes),
            'connection' => $this->config['connection'],
            'queues' => implode(', ', $this->config['queues'] ?? []),
            'balance' => $this->config['balance'] ?? 'simple',
            'last_heartbeat' => (string) microtime(true),
        ]);
    }

    public function terminate(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(10);
            }
        }
        $this->processes = [];
        $this->storage->removeSupervisor($this->name);
    }

    private function spawnWorker(): void
    {
        $command = [
            PHP_BINARY,
            $this->consolePath,
            'messenger:consume',
            $this->config['connection']
        ];

        // Append configured queues if specified
        if (!empty($this->config['queues'])) {
            foreach ($this->config['queues'] as $queue) {
                // If it is different from connection
                if ($queue !== $this->config['connection']) {
                    $command[] = $queue;
                }
            }
        }

        if (isset($this->config['memory_limit'])) {
            $command[] = sprintf('--memory-limit=%dM', $this->config['memory_limit']);
        }
        if (isset($this->config['time_limit'])) {
            $command[] = sprintf('--time-limit=%d', $this->config['time_limit']);
        }
        if (isset($this->config['sleep'])) {
            $command[] = sprintf('--sleep=%d', $this->config['sleep']);
        }

        $process = new Process($command);
        $process->start();

        $this->processes[] = $process;
    }

    private function getPendingCount(): int
    {
        if (!$this->receiverLocator) {
            return 0;
        }

        $totalPending = 0;
        $queues = $this->config['queues'] ?? [$this->config['connection']];

        foreach ($queues as $queueName) {
            if ($this->receiverLocator->has($queueName)) {
                $receiver = $this->receiverLocator->get($queueName);
                if ($receiver instanceof MessageCountAwareInterface) {
                    $totalPending += $receiver->getMessageCount();
                }
            }
        }

        return $totalPending;
    }
}
