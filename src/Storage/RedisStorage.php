<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Storage;

use Saifulferoz\SymfonyHorizon\Collector\JobRecord;

/**
 * Redis-backed storage. Works with phpredis (\Redis) and Predis, plus any
 * duck-typed client exposing pipeline(callable): array (used by tests).
 *
 * All hot-path writes go through a single pipeline per batch. Every key is
 * either trimmed on write (ZREMRANGEBYSCORE) or carries a TTL, so Redis usage
 * is bounded regardless of job volume.
 */
final class RedisStorage implements StorageInterface
{
    private const HEARTBEAT_TTL = 15;
    private const BUCKET_FIELDS_INT = ['jobs', 'failed', 'memory_sum', 'wait_count'];
    private const BUCKET_FIELDS_FLOAT = ['duration_sum', 'wait_sum'];
    /**
     * Bump when the key layout changes incompatibly. Stale keys from another
     * layout (e.g. lists where zsets are expected) make writes fail with
     * WRONGTYPE silently inside pipelines, so on mismatch the prefix is wiped
     * once and re-marked.
     */
    private const SCHEMA_VERSION = '1';

    private ?object $client = null;
    private bool $isPhpRedis = false;
    /** @var array{recent: int, failed: int, metrics: int, snapshots: int} */
    private readonly array $trim;

    /**
     * @param object $redis a client instance, or a \Closure returning one (so
     *                      no connection is opened until storage is first used)
     * @param array{recent?: int, failed?: int, metrics?: int, snapshots?: int} $trim minutes
     */
    public function __construct(
        private readonly object $redis,
        private readonly string $prefix = 'horizon:',
        array $trim = [],
    ) {
        $this->trim = $trim + ['recent' => 60, 'failed' => 10080, 'metrics' => 1440, 'snapshots' => 10080];
    }

    private function client(): object
    {
        if ($this->client === null) {
            $this->client = $this->redis instanceof \Closure ? ($this->redis)() : $this->redis;
            $this->isPhpRedis = \extension_loaded('redis') && $this->client instanceof \Redis;
            $this->ensureSchema();
        }

        return $this->client;
    }

    /**
     * Once per process: wipe keys left behind by an incompatible bundle
     * version. Runs before the first real command, so a fresh install only
     * pays one GET.
     */
    private function ensureSchema(): void
    {
        $marker = $this->prefix . 'schema';
        if ($this->client->get($marker) === self::SCHEMA_VERSION) {
            return;
        }

        $keys = $this->client->keys($this->prefix . '*');
        foreach (array_chunk(\is_array($keys) ? $keys : [], 500) as $chunk) {
            $this->pipeline(static function (object $p) use ($chunk): void {
                foreach ($chunk as $key) {
                    $p->del($key);
                }
            });
        }

        $this->client->set($marker, self::SCHEMA_VERSION);
    }

    public function flush(array $records, array $queueBuckets, array $classBuckets): void
    {
        if ($records === [] && $queueBuckets === []) {
            return;
        }

        $now = microtime(true);
        $completedTtl = $this->trim['recent'] * 60;
        $failedTtl = $this->trim['failed'] * 60;
        $metricsTtl = $this->trim['metrics'] * 60;

        $jobsDelta = 0;
        $failedDelta = 0;
        $allBuckets = [];
        foreach ($queueBuckets as $key => $fields) {
            $minute = substr($key, strrpos($key, '|') + 1);
            foreach ($fields as $field => $value) {
                $allBuckets[$minute][$field] = ($allBuckets[$minute][$field] ?? 0) + $value;
            }
            $jobsDelta += (int) $fields['jobs'];
            $failedDelta += (int) $fields['failed'];
        }

        $this->pipeline(function (object $p) use ($records, $queueBuckets, $classBuckets, $allBuckets, $now, $completedTtl, $failedTtl, $metricsTtl, $jobsDelta, $failedDelta): void {
            foreach ($records as $record) {
                $key = $this->prefix . 'job:' . $record->id;
                $p->hmset($key, $record->toRow());

                if ($record->status === JobRecord::STATUS_FAILED) {
                    $p->expire($key, $failedTtl);
                    $this->zAdd($p, $this->prefix . 'jobs:failed', $record->finishedAt, $record->id);
                } else {
                    $p->expire($key, $completedTtl);
                }
                $this->zAdd($p, $this->prefix . 'jobs:recent', $record->finishedAt, $record->id);
            }

            $this->writeBuckets($p, 'q', $queueBuckets, $metricsTtl, $this->prefix . 'queues');
            $this->writeBuckets($p, 'c', $classBuckets, $metricsTtl, $this->prefix . 'classes');
            foreach ($allBuckets as $minute => $fields) {
                $this->incrementBucket($p, $this->prefix . 'metrics:all:' . $minute, $fields, $metricsTtl);
            }

            if ($jobsDelta > 0) {
                $p->incrby($this->prefix . 'stats:jobs', $jobsDelta);
            }
            if ($failedDelta > 0) {
                $p->incrby($this->prefix . 'stats:failed', $failedDelta);
            }

            // Trim on every flush: O(log N + removed), negligible inside the pipeline.
            $p->zremrangebyscore($this->prefix . 'jobs:recent', '-inf', (string) ($now - $completedTtl));
            $p->zremrangebyscore($this->prefix . 'jobs:failed', '-inf', (string) ($now - $failedTtl));
        });
    }

    public function heartbeatWorker(string $workerId, array $meta): void
    {
        $key = $this->prefix . 'worker:' . $workerId;
        $this->pipeline(function (object $p) use ($key, $workerId, $meta): void {
            $p->hmset($key, $meta);
            $p->expire($key, self::HEARTBEAT_TTL);
            $p->sadd($this->prefix . 'workers', $workerId);
        });
    }

    public function removeWorker(string $workerId): void
    {
        $this->pipeline(function (object $p) use ($workerId): void {
            $p->srem($this->prefix . 'workers', $workerId);
            $p->del($this->prefix . 'worker:' . $workerId);
        });
    }

    public function getWorkers(): array
    {
        return $this->readHeartbeats('workers', 'worker:');
    }

    public function heartbeatSupervisor(string $name, array $meta): void
    {
        $key = $this->prefix . 'supervisor:' . $name;
        $this->pipeline(function (object $p) use ($key, $name, $meta): void {
            $p->hmset($key, $meta);
            $p->expire($key, self::HEARTBEAT_TTL);
            $p->sadd($this->prefix . 'supervisors', $name);
        });
    }

    public function removeSupervisor(string $name): void
    {
        $this->pipeline(function (object $p) use ($name): void {
            $p->srem($this->prefix . 'supervisors', $name);
            $p->del($this->prefix . 'supervisor:' . $name);
        });
    }

    public function getSupervisors(): array
    {
        return $this->readHeartbeats('supervisors', 'supervisor:');
    }

    public function getJob(string $id): ?array
    {
        $row = $this->client()->hgetall($this->prefix . 'job:' . $id);

        return \is_array($row) && $row !== [] ? $row : null;
    }

    public function getRecentJobs(int $limit = 50, int $offset = 0): array
    {
        return $this->pageJobs('jobs:recent', $limit, $offset);
    }

    public function getFailedJobs(int $limit = 50, int $offset = 0): array
    {
        return $this->pageJobs('jobs:failed', $limit, $offset);
    }

    public function deleteFailedJob(string $id): void
    {
        $this->pipeline(function (object $p) use ($id): void {
            $p->zrem($this->prefix . 'jobs:failed', $id);
            $p->zrem($this->prefix . 'jobs:recent', $id);
            $p->del($this->prefix . 'job:' . $id);
        });
    }

    public function markJobRetried(string $id): void
    {
        $this->pipeline(function (object $p) use ($id): void {
            $p->hmset($this->prefix . 'job:' . $id, [
                'status' => 'retried',
                'retried_at' => (string) microtime(true),
            ]);
            $p->zrem($this->prefix . 'jobs:failed', $id);
        });
    }

    public function getCounters(): array
    {
        $results = $this->pipelineRead(function (object $p): void {
            $p->get($this->prefix . 'stats:jobs');
            $p->get($this->prefix . 'stats:failed');
            $p->zcard($this->prefix . 'jobs:recent');
            $p->zcard($this->prefix . 'jobs:failed');
        });

        return [
            'jobs_total' => (int) ($results[0] ?? 0),
            'failed_total' => (int) ($results[1] ?? 0),
            'recent_count' => (int) ($results[2] ?? 0),
            'failed_count' => (int) ($results[3] ?? 0),
        ];
    }

    public function getQueues(): array
    {
        $members = $this->client()->smembers($this->prefix . 'queues');
        sort($members);

        return array_values($members);
    }

    public function getClasses(): array
    {
        $members = $this->client()->smembers($this->prefix . 'classes');
        sort($members);

        return array_values($members);
    }

    public function getMetrics(string $type, string $name, int $minutes = 60): array
    {
        $minutes = max(1, min($minutes, $this->trim['metrics']));
        $nowMinute = (int) (time() / 60);
        $keyBase = $type === 'all'
            ? $this->prefix . 'metrics:all:'
            : $this->prefix . 'metrics:' . $type . ':' . $name . ':';

        $stamps = [];
        for ($i = $minutes - 1; $i >= 0; --$i) {
            $stamps[] = ($nowMinute - $i) * 60;
        }

        $results = $this->pipelineRead(function (object $p) use ($keyBase, $stamps): void {
            foreach ($stamps as $ts) {
                $p->hgetall($keyBase . gmdate('YmdHi', $ts));
            }
        });

        $series = [];
        foreach ($stamps as $i => $ts) {
            $bucket = \is_array($results[$i] ?? null) ? $results[$i] : [];
            $jobs = (int) ($bucket['jobs'] ?? 0);
            $waitCount = (int) ($bucket['wait_count'] ?? 0);

            $series[] = [
                'minute' => gmdate('H:i', $ts),
                'ts' => $ts,
                'jobs' => $jobs,
                'failed' => (int) ($bucket['failed'] ?? 0),
                'avg_duration_ms' => $jobs > 0 ? round((float) ($bucket['duration_sum'] ?? 0) / $jobs, 2) : 0.0,
                'avg_memory' => $jobs > 0 ? (int) ((float) ($bucket['memory_sum'] ?? 0) / $jobs) : 0,
                'avg_wait_ms' => $waitCount > 0 ? round((float) ($bucket['wait_sum'] ?? 0) / $waitCount, 2) : 0.0,
            ];
        }

        return $series;
    }

    public function storeSnapshots(): void
    {
        $now = time();
        $cutoff = $now - $this->trim['snapshots'] * 60;

        foreach (['q' => $this->getQueues(), 'c' => $this->getClasses()] as $type => $names) {
            foreach ($names as $name) {
                $series = $this->getMetrics($type, $name, 60);

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

                $snapshot = json_encode([
                    't' => $now,
                    'jobs' => $jobs,
                    'failed' => $failed,
                    'avg_duration_ms' => $jobs > 0 ? round($durationWeighted / $jobs, 2) : 0,
                    'avg_memory' => $jobs > 0 ? (int) ($memoryWeighted / $jobs) : 0,
                    'avg_wait_ms' => $jobs > 0 ? round($waitWeighted / $jobs, 2) : 0,
                ]);

                $key = $this->prefix . 'snapshot:' . $type . ':' . $name;
                $this->pipeline(function (object $p) use ($key, $now, $snapshot, $cutoff): void {
                    $this->zAdd($p, $key, $now, (string) $snapshot);
                    $p->zremrangebyscore($key, '-inf', (string) $cutoff);
                });
            }
        }
    }

    public function getSnapshots(string $type, string $name, int $limit = 168): array
    {
        $members = $this->client()->zrevrange($this->prefix . 'snapshot:' . $type . ':' . $name, 0, $limit - 1);

        $snapshots = [];
        foreach (\is_array($members) ? $members : [] as $member) {
            $decoded = json_decode((string) $member, true);
            if (\is_array($decoded)) {
                $snapshots[] = $decoded;
            }
        }

        return array_reverse($snapshots);
    }

    public function pushCommand(string $command): void
    {
        $this->client()->rpush($this->prefix . 'commands', $command);
    }

    public function popCommand(): ?string
    {
        $command = $this->client()->lpop($this->prefix . 'commands');

        return \is_string($command) && $command !== '' ? $command : null;
    }

    // --- internals -------------------------------------------------------

    /**
     * @param array<string, array<string, int|float>> $buckets keyed "name|YmdHi"
     */
    private function writeBuckets(object $p, string $type, array $buckets, int $ttl, string $namesSetKey): void
    {
        foreach ($buckets as $bucketKey => $fields) {
            $pos = strrpos($bucketKey, '|');
            $name = substr($bucketKey, 0, $pos);
            $minute = substr($bucketKey, $pos + 1);

            $this->incrementBucket($p, $this->prefix . 'metrics:' . $type . ':' . $name . ':' . $minute, $fields, $ttl);
            $p->sadd($namesSetKey, $name);
        }
    }

    /**
     * @param array<string, int|float> $fields
     */
    private function incrementBucket(object $p, string $key, array $fields, int $ttl): void
    {
        foreach (self::BUCKET_FIELDS_INT as $field) {
            if (($fields[$field] ?? 0) > 0) {
                $p->hincrby($key, $field, (int) $fields[$field]);
            }
        }
        foreach (self::BUCKET_FIELDS_FLOAT as $field) {
            if (($fields[$field] ?? 0) > 0) {
                $p->hincrbyfloat($key, $field, round((float) $fields[$field], 3));
            }
        }
        $p->expire($key, $ttl);
    }

    /**
     * @return array{total: int, jobs: list<array<string, string>>}
     */
    private function pageJobs(string $zsetSuffix, int $limit, int $offset): array
    {
        $zset = $this->prefix . $zsetSuffix;
        $limit = max(1, min($limit, 200));

        $ids = $this->client()->zrevrange($zset, $offset, $offset + $limit - 1);
        $ids = \is_array($ids) ? $ids : [];
        $total = (int) $this->client()->zcard($zset);

        if ($ids === []) {
            return ['total' => $total, 'jobs' => []];
        }

        $results = $this->pipelineRead(function (object $p) use ($ids): void {
            foreach ($ids as $id) {
                $p->hgetall($this->prefix . 'job:' . $id);
            }
        });

        $jobs = [];
        foreach ($ids as $i => $id) {
            $row = $results[$i] ?? null;
            if (\is_array($row) && $row !== []) {
                $row['id'] ??= (string) $id;
                $jobs[] = $row;
            }
        }

        return ['total' => $total, 'jobs' => $jobs];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function readHeartbeats(string $setSuffix, string $keySuffix): array
    {
        $ids = $this->client()->smembers($this->prefix . $setSuffix);
        $ids = \is_array($ids) ? $ids : [];
        if ($ids === []) {
            return [];
        }

        $results = $this->pipelineRead(function (object $p) use ($ids, $keySuffix): void {
            foreach ($ids as $id) {
                $p->hgetall($this->prefix . $keySuffix . $id);
            }
        });

        $alive = [];
        $stale = [];
        foreach ($ids as $i => $id) {
            $meta = $results[$i] ?? null;
            if (\is_array($meta) && $meta !== []) {
                $alive[(string) $id] = $meta;
            } else {
                $stale[] = $id; // heartbeat TTL expired: process died without cleanup
            }
        }

        if ($stale !== []) {
            $this->pipeline(function (object $p) use ($stale, $setSuffix): void {
                foreach ($stale as $id) {
                    $p->srem($this->prefix . $setSuffix, $id);
                }
            });
        }

        ksort($alive);

        return $alive;
    }

    private function pipeline(callable $commands): void
    {
        $this->pipelineRead($commands);
    }

    /**
     * Runs $commands against a pipelined connection and returns the per-command replies.
     *
     * @return list<mixed>
     */
    private function pipelineRead(callable $commands): array
    {
        $client = $this->client(); // resolve first: this also decides $isPhpRedis

        if ($this->isPhpRedis) {
            $pipe = $client->multi(\Redis::PIPELINE);
            $commands($pipe);
            $replies = $pipe->exec();

            return \is_array($replies) ? $replies : [];
        }

        if (method_exists($client, 'pipeline')) {
            $replies = $client->pipeline(static function (object $pipe) use ($commands): void {
                $commands($pipe);
            });

            return \is_array($replies) ? array_values($replies) : [];
        }

        throw new \LogicException(sprintf('Unsupported Redis client "%s": expected phpredis, Predis, or a client exposing pipeline(callable).', $client::class));
    }

    private function zAdd(object $connection, string $key, float $score, string $member): void
    {
        if ($this->isPhpRedis) {
            $connection->zadd($key, $score, $member);
        } else {
            // Predis signature
            $connection->zadd($key, [$member => $score]);
        }
    }
}
