<?php

namespace Saifulferoz\SymfonyHorizon\Storage;

interface StorageInterface
{
    /**
     * Records supervisor status and sets a heartbeat TTL.
     */
    public function recordSupervisorHeartbeat(string $name, array $meta): void;

    /**
     * Returns list of all active supervisors.
     */
    public function getSupervisors(): array;

    /**
     * Removes supervisor status (e.g. on exit).
     */
    public function removeSupervisor(string $name): void;

    /**
     * Records worker status.
     */
    public function recordWorkerHeartbeat(string $workerId, array $meta): void;

    /**
     * Returns list of all active workers.
     */
    public function getWorkers(): array;

    /**
     * Removes worker status.
     */
    public function removeWorker(string $workerId): void;

    /**
     * Records that a job was received for processing.
     */
    public function recordJobReceived(string $jobId, array $jobData): void;

    /**
     * Records that a job completed successfully.
     */
    public function recordJobHandled(string $jobId, float $duration): void;

    /**
     * Records that a job failed to process.
     */
    public function recordJobFailed(string $jobId, string $errorMessage, string $stackTrace): void;

    /**
     * Fetches details of a specific job.
     */
    public function getJobDetails(string $jobId): ?array;

    /**
     * Fetches recent completed/processed jobs.
     */
    public function getRecentJobs(int $limit = 50, int $offset = 0): array;

    /**
     * Fetches failed jobs.
     */
    public function getFailedJobs(int $limit = 50, int $offset = 0): array;

    /**
     * Deletes a failed job from the failed list.
     */
    public function deleteFailedJob(string $jobId): void;

    /**
     * Gets statistics dashboard overview metrics.
     */
    public function getDashboardMetrics(): array;
}
