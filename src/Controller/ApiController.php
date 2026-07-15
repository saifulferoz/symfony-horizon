<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Controller;

use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API polled by the dashboard.
 */
final class ApiController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly FailedJobRetryer $retryer,
    ) {
    }

    public function stats(): JsonResponse
    {
        $counters = $this->storage->getCounters();
        $series = $this->storage->getMetrics('all', 'all', 60);
        $workers = $this->storage->getWorkers();
        $supervisors = $this->storage->getSupervisors();

        $jobsLastHour = 0;
        $failedLastHour = 0;
        $durationWeighted = 0.0;
        $memoryWeighted = 0.0;
        $waitWeighted = 0.0;
        foreach ($series as $point) {
            $jobsLastHour += $point['jobs'];
            $failedLastHour += $point['failed'];
            $durationWeighted += $point['avg_duration_ms'] * $point['jobs'];
            $memoryWeighted += $point['avg_memory'] * $point['jobs'];
            $waitWeighted += $point['avg_wait_ms'] * $point['jobs'];
        }

        $lastMinute = $series !== [] ? $series[\count($series) - 2] ?? $series[0] : null;

        return new JsonResponse([
            'jobs_total' => $counters['jobs_total'],
            'failed_total' => $counters['failed_total'],
            'failed_count' => $counters['failed_count'],
            'jobs_per_minute' => $lastMinute['jobs'] ?? 0,
            'jobs_last_hour' => $jobsLastHour,
            'failed_last_hour' => $failedLastHour,
            'avg_duration_ms' => $jobsLastHour > 0 ? round($durationWeighted / $jobsLastHour, 2) : 0,
            'avg_memory' => $jobsLastHour > 0 ? (int) ($memoryWeighted / $jobsLastHour) : 0,
            'avg_wait_ms' => $jobsLastHour > 0 ? round($waitWeighted / $jobsLastHour, 2) : 0,
            'workers' => \count($workers),
            'supervisors' => \count($supervisors),
            'series' => $series,
        ]);
    }

    public function workers(): JsonResponse
    {
        $workers = [];
        foreach ($this->storage->getWorkers() as $id => $meta) {
            $meta['id'] = $meta['id'] ?? (string) $id;
            $meta['processed'] = (int) ($meta['processed'] ?? 0);
            $meta['failed'] = (int) ($meta['failed'] ?? 0);
            $meta['memory'] = (int) ($meta['memory'] ?? 0);
            $meta['started_at'] = (float) ($meta['started_at'] ?? 0);
            $workers[] = $meta;
        }

        return new JsonResponse(['workers' => $workers]);
    }

    public function supervisors(): JsonResponse
    {
        $supervisors = [];
        foreach ($this->storage->getSupervisors() as $name => $meta) {
            $meta['name'] = $meta['name'] ?? (string) $name;
            $meta['processes'] = (int) ($meta['processes'] ?? 0);
            $pending = json_decode($meta['pending'] ?? '{}', true);
            $meta['pending'] = \is_array($pending) ? $pending : [];
            $supervisors[] = $meta;
        }

        return new JsonResponse(['supervisors' => $supervisors]);
    }

    public function recentJobs(Request $request): JsonResponse
    {
        return $this->jobPage($this->storage->getRecentJobs(...$this->pagination($request)));
    }

    public function failedJobs(Request $request): JsonResponse
    {
        return $this->jobPage($this->storage->getFailedJobs(...$this->pagination($request)));
    }

    public function job(string $id): JsonResponse
    {
        $job = $this->storage->getJob($id);
        if ($job === null) {
            return new JsonResponse(['error' => 'Job not found'], 404);
        }

        return new JsonResponse(['job' => $this->normalizeJob($job, detailed: true)]);
    }

    public function retryJob(string $id): JsonResponse
    {
        try {
            $this->retryer->retry($id);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function deleteJob(string $id): JsonResponse
    {
        $this->storage->deleteFailedJob($id);

        return new JsonResponse(['ok' => true]);
    }

    public function queueMetrics(Request $request): JsonResponse
    {
        return $this->metrics($request, 'q', $this->storage->getQueues());
    }

    public function classMetrics(Request $request): JsonResponse
    {
        return $this->metrics($request, 'c', $this->storage->getClasses());
    }

    // --- internals -------------------------------------------------------

    /**
     * @param list<string> $names
     */
    private function metrics(Request $request, string $type, array $names): JsonResponse
    {
        $name = (string) $request->query->get('name', '');
        $minutes = max(5, min(1440, $request->query->getInt('minutes', 60)));

        if ($name !== '') {
            return new JsonResponse([
                'name' => $name,
                'series' => $this->storage->getMetrics($type, $name, $minutes),
                'snapshots' => $this->storage->getSnapshots($type, $name),
            ]);
        }

        $summaries = [];
        foreach ($names as $candidate) {
            $series = $this->storage->getMetrics($type, $candidate, $minutes);

            $jobs = 0;
            $failed = 0;
            $durationWeighted = 0.0;
            $memoryWeighted = 0.0;
            $waitWeighted = 0.0;
            foreach ($series as $point) {
                $jobs += $point['jobs'];
                $failed += $point['failed'];
                $durationWeighted += $point['avg_duration_ms'] * $point['jobs'];
                $memoryWeighted += $point['avg_memory'] * $point['jobs'];
                $waitWeighted += $point['avg_wait_ms'] * $point['jobs'];
            }

            $summaries[] = [
                'name' => $candidate,
                'jobs' => $jobs,
                'failed' => $failed,
                'avg_duration_ms' => $jobs > 0 ? round($durationWeighted / $jobs, 2) : 0,
                'avg_memory' => $jobs > 0 ? (int) ($memoryWeighted / $jobs) : 0,
                'avg_wait_ms' => $jobs > 0 ? round($waitWeighted / $jobs, 2) : 0,
            ];
        }

        return new JsonResponse(['minutes' => $minutes, 'items' => $summaries]);
    }

    /**
     * @return array{0: int, 1: int} [limit, offset]
     */
    private function pagination(Request $request): array
    {
        return [
            max(1, min(100, $request->query->getInt('limit', 25))),
            max(0, $request->query->getInt('offset', 0)),
        ];
    }

    /**
     * @param array{total: int, jobs: list<array<string, string>>} $page
     */
    private function jobPage(array $page): JsonResponse
    {
        return new JsonResponse([
            'total' => $page['total'],
            'jobs' => array_map(fn (array $job) => $this->normalizeJob($job), $page['jobs']),
        ]);
    }

    /**
     * @param array<string, string> $job
     *
     * @return array<string, mixed>
     */
    private function normalizeJob(array $job, bool $detailed = false): array
    {
        $tags = json_decode($job['tags'] ?? '[]', true);

        $normalized = [
            'id' => $job['id'] ?? '',
            'class' => $job['class'] ?? '',
            'queue' => $job['queue'] ?? '',
            'status' => $job['status'] ?? '',
            'duration_ms' => (float) ($job['duration_ms'] ?? 0),
            'memory_used' => (int) ($job['memory_used'] ?? 0),
            'peak_memory' => (int) ($job['peak_memory'] ?? 0),
            'wait_ms' => isset($job['wait_ms']) ? (float) $job['wait_ms'] : null,
            'attempts' => (int) ($job['attempts'] ?? 1),
            'worker_id' => $job['worker_id'] ?? '',
            'received_at' => (float) ($job['received_at'] ?? 0),
            'finished_at' => (float) ($job['finished_at'] ?? 0),
            'tags' => \is_array($tags) ? $tags : [],
            'exception_class' => $job['exception_class'] ?? null,
            'exception_message' => $job['exception_message'] ?? null,
            'retryable' => ($job['retry_payload'] ?? '') !== '',
        ];

        if ($detailed) {
            $normalized['payload'] = $job['payload'] ?? null;
            $normalized['exception_trace'] = $job['exception_trace'] ?? null;
            $normalized['retried_at'] = isset($job['retried_at']) ? (float) $job['retried_at'] : null;
        }

        return $normalized;
    }
}
