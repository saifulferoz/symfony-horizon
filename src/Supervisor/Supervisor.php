<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Supervisor;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

/**
 * One configured supervisor block: keeps its worker pool at the right size,
 * autoscales on queue depth, and reports a heartbeat for the dashboard.
 */
final class Supervisor
{
    private bool $paused = false;
    private float $lastScaleAt = 0.0;
    private float $lastHeartbeatAt = 0.0;
    /** @var array<string, int|null> */
    private array $lastPending = [];
    /**
     * Identity of this supervisor *instance*. The configured name is shared by
     * every host running the same config, so the heartbeat is keyed by
     * name@host:pid plus a random suffix — otherwise instances overwrite each
     * other's heartbeat and the dashboard shows one supervisor with the wrong
     * process count. The suffix also separates two supervisors that share a
     * host and PID (several blocks in one horizon process, or two processes
     * started in the same container).
     */
    private readonly string $instanceId;

    /**
     * @param array{transports: list<string>, min_processes: int, max_processes: int, balance: string, scale_factor: int, autoscale_cooldown: int} $config
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
        private readonly WorkerPool $pool,
        private readonly AutoScaler $autoScaler,
        private readonly QueueDepthProvider $queueDepths,
        private readonly StorageInterface $storage,
    ) {
        $this->instanceId = sprintf(
            '%s@%s:%d.%s',
            $name,
            gethostname() ?: 'unknown',
            getmypid() ?: 0,
            bin2hex(random_bytes(3)),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Cluster-unique id for this supervisor instance (name@host:pid).
     */
    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function tick(): void
    {
        $reaped = $this->pool->reap();
        $now = microtime(true);

        if (!$this->paused && ($reaped > 0 || ($now - $this->lastScaleAt) >= $this->config['autoscale_cooldown'])) {
            $this->lastScaleAt = $now;
            $this->pool->scaleTo($this->desiredProcesses());
        }

        if (($now - $this->lastHeartbeatAt) >= 5.0) {
            $this->heartbeat();
        }
    }

    public function pause(): void
    {
        $this->paused = true;
        $this->pool->scaleTo(0);
        $this->heartbeat();
    }

    public function resume(): void
    {
        $this->paused = false;
        $this->lastScaleAt = 0.0;
        $this->heartbeat();
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function stop(): void
    {
        $this->pool->stopAll();
        $this->storage->removeSupervisor($this->instanceId);
    }

    private function desiredProcesses(): int
    {
        if ($this->config['balance'] !== 'auto') {
            $this->lastPending = $this->queueDepths->counts($this->config['transports']);

            return max(1, $this->config['min_processes']);
        }

        $this->lastPending = $this->queueDepths->counts($this->config['transports']);
        $pending = null;
        foreach ($this->lastPending as $count) {
            if ($count !== null) {
                $pending = ($pending ?? 0) + $count;
            }
        }

        return $this->autoScaler->desiredProcesses(
            $pending,
            $this->config['min_processes'],
            $this->config['max_processes'],
            $this->config['scale_factor'],
        );
    }

    private function heartbeat(): void
    {
        $this->lastHeartbeatAt = microtime(true);

        $this->storage->heartbeatSupervisor($this->instanceId, [
            'id' => $this->instanceId,
            'name' => $this->name,
            'pid' => (string) getmypid(),
            'host' => gethostname() ?: 'unknown',
            'status' => $this->paused ? 'paused' : 'running',
            'processes' => (string) $this->pool->count(),
            'min_processes' => (string) $this->config['min_processes'],
            'max_processes' => (string) $this->config['max_processes'],
            'balance' => $this->config['balance'],
            'transports' => implode(',', $this->config['transports']),
            'pending' => json_encode($this->lastPending) ?: '{}',
            'updated_at' => (string) microtime(true),
        ]);
    }
}
