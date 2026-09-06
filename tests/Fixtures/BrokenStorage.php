<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Fixtures;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

/**
 * Storage that fails on every write, standing in for an unreachable Redis.
 */
final class BrokenStorage implements StorageInterface
{
    public int $flushAttempts = 0;
    public int $heartbeatAttempts = 0;

    public function flush(array $records, array $queueBuckets, array $classBuckets): void
    {
        ++$this->flushAttempts;
        throw new \RuntimeException('Connection refused');
    }

    public function heartbeatWorker(string $workerId, array $meta): void
    {
        ++$this->heartbeatAttempts;
        throw new \RuntimeException('Connection refused');
    }

    public function removeWorker(string $workerId): void
    {
        throw new \RuntimeException('Connection refused');
    }

    public function getWorkers(): array
    {
        return [];
    }

    public function heartbeatSupervisor(string $name, array $meta): void
    {
        throw new \RuntimeException('Connection refused');
    }

    public function removeSupervisor(string $name): void
    {
    }

    public function getSupervisors(): array
    {
        return [];
    }

    public function getJob(string $id): ?array
    {
        return null;
    }

    public function getRecentJobs(int $limit = 50, int $offset = 0): array
    {
        return ['total' => 0, 'jobs' => []];
    }

    public function getFailedJobs(int $limit = 50, int $offset = 0): array
    {
        return ['total' => 0, 'jobs' => []];
    }

    public function deleteFailedJob(string $id): void
    {
    }

    public function markJobRetried(string $id): void
    {
    }

    public function getCounters(): array
    {
        return ['jobs_total' => 0, 'failed_total' => 0, 'recent_count' => 0, 'failed_count' => 0];
    }

    public function getQueues(): array
    {
        return [];
    }

    public function getClasses(): array
    {
        return [];
    }

    public function getMetrics(string $type, string $name, int $minutes = 60): array
    {
        return [];
    }

    public function storeSnapshots(): void
    {
    }

    public function getSnapshots(string $type, string $name, int $limit = 168): array
    {
        return [];
    }

    public function pushCommand(string $command): void
    {
        throw new \RuntimeException('Connection refused');
    }

    public function readCommand(): ?array
    {
        throw new \RuntimeException('Connection refused');
    }

    public function acquireLock(string $name, int $ttlSeconds): bool
    {
        throw new \RuntimeException('Connection refused');
    }
}
