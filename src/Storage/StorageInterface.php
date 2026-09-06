<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Storage;

use Saifulferoz\SymfonyHorizon\Collector\JobRecord;

interface StorageInterface
{
    public const CMD_PAUSE = 'pause';
    public const CMD_CONTINUE = 'continue';
    public const CMD_TERMINATE = 'terminate';

    /**
     * Persists a batch of job records and per-minute metric buckets in a single
     * round-trip. Bucket keys are "name|YmdHi" (UTC minute), bucket values are
     * {jobs, failed, duration_sum, memory_sum, wait_sum, wait_count}.
     *
     * @param list<JobRecord>                        $records
     * @param array<string, array<string, int|float>> $queueBuckets
     * @param array<string, array<string, int|float>> $classBuckets
     */
    public function flush(array $records, array $queueBuckets, array $classBuckets): void;

    /** @param array<string, string> $meta */
    public function heartbeatWorker(string $workerId, array $meta): void;

    public function removeWorker(string $workerId): void;

    /** @return array<string, array<string, string>> */
    public function getWorkers(): array;

    /** @param array<string, string> $meta */
    public function heartbeatSupervisor(string $name, array $meta): void;

    public function removeSupervisor(string $name): void;

    /** @return array<string, array<string, string>> */
    public function getSupervisors(): array;

    /** @return array<string, string>|null */
    public function getJob(string $id): ?array;

    /** @return array{total: int, jobs: list<array<string, string>>} */
    public function getRecentJobs(int $limit = 50, int $offset = 0): array;

    /** @return array{total: int, jobs: list<array<string, string>>} */
    public function getFailedJobs(int $limit = 50, int $offset = 0): array;

    public function deleteFailedJob(string $id): void;

    public function markJobRetried(string $id): void;

    /**
     * Global counters and totals for the dashboard overview.
     *
     * @return array{jobs_total: int, failed_total: int, recent_count: int, failed_count: int}
     */
    public function getCounters(): array;

    /** @return list<string> */
    public function getQueues(): array;

    /** @return list<string> */
    public function getClasses(): array;

    /**
     * Per-minute series, oldest first. Type "q" = queue, "c" = class,
     * "all" = every queue combined (pass name "all").
     *
     * @return list<array{minute: string, ts: int, jobs: int, failed: int, avg_duration_ms: float, avg_memory: int, avg_wait_ms: float}>
     */
    public function getMetrics(string $type, string $name, int $minutes = 60): array;

    /**
     * Rolls the last hour of minute buckets into one snapshot per queue.
     */
    public function storeSnapshots(): void;

    /** @return list<array<string, int|float|string>> */
    public function getSnapshots(string $type, string $name, int $limit = 168): array;

    /**
     * Broadcasts a control command to every running horizon instance.
     *
     * Commands are stored as a monotonically versioned state rather than a
     * queue: a queue is drained by whichever instance polls first, which on a
     * multi-host deployment would pause one instance and leave the others
     * consuming.
     */
    public function pushCommand(string $command): void;

    /**
     * Returns the current control command and its version, or null if none was
     * ever issued. Every instance reads the same state, so each can apply a
     * command exactly once by tracking the version it last saw.
     *
     * @return array{command: string, version: int}|null
     */
    public function readCommand(): ?array;

    /**
     * Attempts to acquire a short-lived, cluster-wide lock. Returns false when
     * another instance already holds it. Used to keep periodic work (e.g.
     * snapshots) idempotent when it runs on several hosts.
     */
    public function acquireLock(string $name, int $ttlSeconds): bool;
}
