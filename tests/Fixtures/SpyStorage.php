<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Fixtures;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

/**
 * Records every call so tests can assert on batching behaviour.
 */
final class SpyStorage implements StorageInterface
{
    /** @var list<array{records: list<\Saifulferoz\SymfonyHorizon\Collector\JobRecord>, queueBuckets: array<string, array<string, int|float>>, classBuckets: array<string, array<string, int|float>>}> */
    public array $flushes = [];
    /** @var list<array{0: string, 1: array<string, string>}> */
    public array $workerHeartbeats = [];
    /** @var list<string> */
    public array $removedWorkers = [];
    /** @var list<array{0: string, 1: array<string, string>}> */
    public array $supervisorHeartbeats = [];
    /** @var list<string> */
    public array $removedSupervisors = [];
    /** @var list<string> */
    public array $retriedJobs = [];
    /** @var array<string, array<string, string>> */
    public array $jobs = [];
    /** @var list<string> */
    public array $commands = [];

    public function flush(array $records, array $queueBuckets, array $classBuckets): void
    {
        $this->flushes[] = ['records' => $records, 'queueBuckets' => $queueBuckets, 'classBuckets' => $classBuckets];
    }

    public function heartbeatWorker(string $workerId, array $meta): void
    {
        $this->workerHeartbeats[] = [$workerId, $meta];
    }

    public function removeWorker(string $workerId): void
    {
        $this->removedWorkers[] = $workerId;
    }

    public function getWorkers(): array
    {
        return [];
    }

    public function heartbeatSupervisor(string $name, array $meta): void
    {
        $this->supervisorHeartbeats[] = [$name, $meta];
    }

    public function removeSupervisor(string $name): void
    {
        $this->removedSupervisors[] = $name;
    }

    public function getSupervisors(): array
    {
        return [];
    }

    public function getJob(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
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
        unset($this->jobs[$id]);
    }

    public function markJobRetried(string $id): void
    {
        $this->retriedJobs[] = $id;
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
        $this->commands[] = $command;
    }

    public function popCommand(): ?string
    {
        return array_shift($this->commands);
    }
}
