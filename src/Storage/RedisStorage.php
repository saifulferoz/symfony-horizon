<?php

namespace Saifulferoz\SymfonyHorizon\Storage;

class RedisStorage implements StorageInterface
{
    private object $redis;
    private string $prefix;

    public function __construct(object $redis, string $prefix = 'horizon:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function recordSupervisorHeartbeat(string $name, array $meta): void
    {
        $key = $this->prefix . 'supervisor:' . $name;
        $this->hMSet($key, $meta);
        $this->redis->expire($key, 60);

        $this->redis->sadd($this->prefix . 'supervisors', $name);
    }

    public function getSupervisors(): array
    {
        $names = $this->redis->smembers($this->prefix . 'supervisors');
        $supervisors = [];

        foreach ($names as $name) {
            $key = $this->prefix . 'supervisor:' . $name;
            $meta = $this->redis->hgetall($key);
            if ($meta) {
                $supervisors[$name] = $meta;
            } else {
                $this->redis->srem($this->prefix . 'supervisors', $name);
            }
        }

        return $supervisors;
    }

    public function removeSupervisor(string $name): void
    {
        $this->redis->srem($this->prefix . 'supervisors', $name);
        $this->redis->del($this->prefix . 'supervisor:' . $name);
    }

    public function recordWorkerHeartbeat(string $workerId, array $meta): void
    {
        $key = $this->prefix . 'worker:' . $workerId;
        $this->hMSet($key, $meta);
        $this->redis->expire($key, 60);

        $this->redis->sadd($this->prefix . 'workers', $workerId);
    }

    public function getWorkers(): array
    {
        $ids = $this->redis->smembers($this->prefix . 'workers');
        $workers = [];

        foreach ($ids as $id) {
            $key = $this->prefix . 'worker:' . $id;
            $meta = $this->redis->hgetall($key);
            if ($meta) {
                $workers[$id] = $meta;
            } else {
                $this->redis->srem($this->prefix . 'workers', $id);
            }
        }

        return $workers;
    }

    public function removeWorker(string $workerId): void
    {
        $this->redis->srem($this->prefix . 'workers', $workerId);
        $this->redis->del($this->prefix . 'worker:' . $workerId);
    }

    public function recordJobReceived(string $jobId, array $jobData): void
    {
        $key = $this->prefix . 'job:' . $jobId;
        $this->hMSet($key, $jobData);
        $this->redis->expire($key, 86400); // 24 hours retention for jobs

        // Add to recent jobs list
        $this->redis->lpush($this->prefix . 'jobs:recent', $jobId);
        $this->redis->ltrim($this->prefix . 'jobs:recent', 0, 999); // Cap at 1000 items

        $this->redis->incr($this->prefix . 'stats:total_jobs');
    }

    public function recordJobHandled(string $jobId, float $duration): void
    {
        $key = $this->prefix . 'job:' . $jobId;
        $this->hMSet($key, [
            'status' => 'completed',
            'duration' => (string) $duration,
            'completed_at' => (string) microtime(true),
        ]);

        $timestamp = time();
        $this->zAdd($this->prefix . 'stats:throughput', $timestamp, $jobId);
        $this->zAdd($this->prefix . 'stats:runtimes', $duration, $jobId);

        // Keep stats only for last 24 hours
        $cutoff = $timestamp - 86400;
        if (method_exists($this->redis, 'zremrangebyscore')) {
            $this->redis->zremrangebyscore($this->prefix . 'stats:throughput', '-inf', (string) $cutoff);
        } else {
            $this->redis->zRemRangeByScore($this->prefix . 'stats:throughput', '-inf', (string) $cutoff);
        }
    }

    public function recordJobFailed(string $jobId, string $errorMessage, string $stackTrace, array $jobData = []): void
    {
        $key = $this->prefix . 'job:' . $jobId;
        $this->hMSet($key, array_merge($jobData, [
            'status' => 'failed',
            'failed_at' => (string) microtime(true),
            'exception' => $errorMessage,
            'stack_trace' => $stackTrace,
        ]));

        $this->redis->lpush($this->prefix . 'jobs:failed', $jobId);
        $this->redis->ltrim($this->prefix . 'jobs:failed', 0, 999);

        $this->redis->incr($this->prefix . 'stats:failed_jobs');
    }

    public function getJobDetails(string $jobId): ?array
    {
        $data = $this->redis->hgetall($this->prefix . 'job:' . $jobId);
        return $data ?: null;
    }

    public function getRecentJobs(int $limit = 50, int $offset = 0): array
    {
        $end = $offset + $limit - 1;
        $ids = $this->redis->lrange($this->prefix . 'jobs:recent', $offset, $end) ?: [];
        $jobs = [];

        foreach ($ids as $id) {
            $job = $this->getJobDetails($id);
            if ($job) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function getFailedJobs(int $limit = 50, int $offset = 0): array
    {
        $end = $offset + $limit - 1;
        $ids = $this->redis->lrange($this->prefix . 'jobs:failed', $offset, $end) ?: [];
        $jobs = [];

        foreach ($ids as $id) {
            $job = $this->getJobDetails($id);
            if ($job) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function deleteFailedJob(string $jobId): void
    {
        // Remove from list
        $this->redis->lrem($this->prefix . 'jobs:failed', 0, $jobId);
        // Delete job payload
        $this->redis->del($this->prefix . 'job:' . $jobId);
    }

    public function getDashboardMetrics(): array
    {
        $totalJobs = (int) ($this->redis->get($this->prefix . 'stats:total_jobs') ?: 0);
        $failedJobs = (int) ($this->redis->get($this->prefix . 'stats:failed_jobs') ?: 0);
        
        // Calculate throughput in last minute
        $now = time();
        $oneMinuteAgo = $now - 60;
        
        if (method_exists($this->redis, 'zcount')) {
            $throughput = (int) ($this->redis->zcount($this->prefix . 'stats:throughput', (string) $oneMinuteAgo, (string) $now) ?: 0);
        } else {
            $throughput = (int) ($this->redis->zCount($this->prefix . 'stats:throughput', (string) $oneMinuteAgo, (string) $now) ?: 0);
        }

        return [
            'total_jobs' => $totalJobs,
            'failed_jobs' => $failedJobs,
            'throughput_per_minute' => $throughput,
        ];
    }

    private function hMSet(string $key, array $association): void
    {
        if (method_exists($this->redis, 'hMSet')) {
            $this->redis->hMSet($key, $association);
        } else {
            $this->redis->hmset($key, $association);
        }
    }

    private function zAdd(string $key, float $score, string $value): void
    {
        if (method_exists($this->redis, 'zAdd')) {
            $this->redis->zAdd($key, $score, $value);
        } else {
            $this->redis->zadd($key, $score, $value);
        }
    }
}
