<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Realtime;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

/**
 * Builds the dashboard payloads.
 *
 * Shared deliberately by the JSON API and the real-time broadcaster: both
 * transports must emit byte-identical payloads, or the dashboard behaves
 * subtly differently depending on how it is fed.
 */
final class SnapshotBuilder
{
    public function __construct(private readonly StorageInterface $storage)
    {
    }

    /**
     * Overview counters plus the last hour of per-minute series.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
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

        // The final bucket is the current, still-filling minute; the one before
        // it is the last complete minute and therefore the honest rate.
        $lastMinute = $series !== [] ? $series[\count($series) - 2] ?? $series[0] : null;

        return [
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
        ];
    }

    /**
     * @return array{workers: list<array<string, mixed>>}
     */
    public function workers(): array
    {
        $workers = [];
        foreach ($this->storage->getWorkers() as $id => $meta) {
            $meta['id'] ??= (string) $id;
            $meta['processed'] = (int) ($meta['processed'] ?? 0);
            $meta['failed'] = (int) ($meta['failed'] ?? 0);
            $meta['memory'] = (int) ($meta['memory'] ?? 0);
            $meta['started_at'] = (float) ($meta['started_at'] ?? 0);
            $workers[] = $meta;
        }

        return ['workers' => $workers];
    }

    /**
     * @return array{supervisors: list<array<string, mixed>>}
     */
    public function supervisors(): array
    {
        $supervisors = [];
        // Keys are per-instance ids (name@host:pid), so the same configured
        // name legitimately appears once per host running it.
        foreach ($this->storage->getSupervisors() as $instanceId => $meta) {
            $meta['id'] ??= (string) $instanceId;
            $meta['name'] ??= (string) $instanceId;
            $meta['processes'] = (int) ($meta['processes'] ?? 0);
            $pending = json_decode($meta['pending'] ?? '{}', true);
            $meta['pending'] = \is_array($pending) ? $pending : [];
            $supervisors[] = $meta;
        }

        return ['supervisors' => $supervisors];
    }
}
