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
    }

    public function name(): string
    {
        return $this->name;
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
        $this->storage->removeSupervisor($this->name);
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

        $this->storage->heartbeatSupervisor($this->name, [
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
