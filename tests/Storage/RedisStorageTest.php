<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Collector\JobRecord;
use Saifulferoz\SymfonyHorizon\Storage\RedisStorage;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\FakeRedis;

final class RedisStorageTest extends TestCase
{
    private FakeRedis $redis;
    private RedisStorage $storage;

    protected function setUp(): void
    {
        $this->redis = new FakeRedis();
        $this->storage = new RedisStorage($this->redis, 'horizon:');
    }

    private function record(string $id, string $status = JobRecord::STATUS_COMPLETED, ?float $finishedAt = null): JobRecord
    {
        return new JobRecord(
            id: $id,
            class: 'App\\Message\\Demo',
            queue: 'async',
            status: $status,
            durationMs: 12.5,
            memoryUsed: 1024,
            peakMemory: 4 * 1024 * 1024,
            waitMs: 40.0,
            attempts: 1,
            workerId: '123@host',
            receivedAt: microtime(true) - 1,
            finishedAt: $finishedAt ?? microtime(true),
            tags: ['demo'],
            exceptionClass: $status === JobRecord::STATUS_FAILED ? \RuntimeException::class : null,
            exceptionMessage: $status === JobRecord::STATUS_FAILED ? 'boom' : null,
            exceptionTrace: $status === JobRecord::STATUS_FAILED ? '#0 trace' : null,
            retryPayload: $status === JobRecord::STATUS_FAILED ? base64_encode(serialize(new \stdClass())) : null,
        );
    }

    /** @return array<string, array<string, int|float>> */
    private function bucket(string $name, int $jobs = 1, int $failed = 0, float $durationSum = 12.5): array
    {
        return [$name . '|' . gmdate('YmdHi') => [
            'jobs' => $jobs,
            'failed' => $failed,
            'duration_sum' => $durationSum,
            'memory_sum' => $jobs * 4 * 1024 * 1024,
            'wait_sum' => $jobs * 40.0,
            'wait_count' => $jobs,
        ]];
    }

    public function testFlushPersistsJobsBucketsAndCounters(): void
    {
        $this->storage->flush(
            [$this->record('job1'), $this->record('job2', JobRecord::STATUS_FAILED)],
            $this->bucket('async', jobs: 2, failed: 1, durationSum: 25.0),
            $this->bucket('App\\Message\\Demo', jobs: 2, failed: 1, durationSum: 25.0),
        );

        self::assertSame('completed', $this->redis->hashes['horizon:job:job1']['status']);
        self::assertSame('failed', $this->redis->hashes['horizon:job:job2']['status']);
        self::assertSame('boom', $this->redis->hashes['horizon:job:job2']['exception_message']);

        self::assertArrayHasKey('job1', $this->redis->zsets['horizon:jobs:recent']);
        self::assertArrayHasKey('job2', $this->redis->zsets['horizon:jobs:recent']);
        self::assertArrayHasKey('job2', $this->redis->zsets['horizon:jobs:failed']);
        self::assertArrayNotHasKey('job1', $this->redis->zsets['horizon:jobs:failed'] ?? []);

        self::assertSame('2', $this->redis->strings['horizon:stats:jobs']);
        self::assertSame('1', $this->redis->strings['horizon:stats:failed']);
        self::assertSame(['async'], $this->redis->smembers('horizon:queues'));
        self::assertSame(['App\\Message\\Demo'], $this->redis->smembers('horizon:classes'));

        // every job hash gets a TTL so Redis stays bounded
        self::assertArrayHasKey('horizon:job:job1', $this->redis->ttls);
        self::assertArrayHasKey('horizon:job:job2', $this->redis->ttls);
        self::assertGreaterThan($this->redis->ttls['horizon:job:job1'], $this->redis->ttls['horizon:job:job2'], 'failed jobs are kept longer');
    }

    public function testRecentJobsArePagedNewestFirst(): void
    {
        $now = microtime(true);
        $this->storage->flush(
            [
                $this->record('old', finishedAt: $now - 30),
                $this->record('mid', finishedAt: $now - 20),
                $this->record('new', finishedAt: $now - 10),
            ],
            $this->bucket('async', jobs: 3),
            [],
        );

        $page = $this->storage->getRecentJobs(limit: 2);
        self::assertSame(3, $page['total']);
        self::assertSame(['new', 'mid'], array_column($page['jobs'], 'id'));

        $page2 = $this->storage->getRecentJobs(limit: 2, offset: 2);
        self::assertSame(['old'], array_column($page2['jobs'], 'id'));
    }

    public function testDeleteFailedJobRemovesEverything(): void
    {
        $this->storage->flush([$this->record('bad', JobRecord::STATUS_FAILED)], $this->bucket('async'), []);

        $this->storage->deleteFailedJob('bad');

        self::assertNull($this->storage->getJob('bad'));
        self::assertSame(0, $this->storage->getFailedJobs()['total']);
        self::assertSame(0, $this->storage->getRecentJobs()['total']);
    }

    public function testMarkJobRetriedUpdatesStatusAndLeavesFailedList(): void
    {
        $this->storage->flush([$this->record('bad', JobRecord::STATUS_FAILED)], $this->bucket('async'), []);

        $this->storage->markJobRetried('bad');

        self::assertSame('retried', $this->storage->getJob('bad')['status']);
        self::assertSame(0, $this->storage->getFailedJobs()['total']);
    }

    public function testMetricsSeriesComputesAverages(): void
    {
        $this->storage->flush(
            [$this->record('job1'), $this->record('job2')],
            $this->bucket('async', jobs: 2, durationSum: 50.0),
            [],
        );

        $series = $this->storage->getMetrics('q', 'async', 5);
        self::assertCount(5, $series);

        $current = end($series);
        self::assertSame(2, $current['jobs']);
        self::assertSame(25.0, $current['avg_duration_ms']);
        self::assertSame(4 * 1024 * 1024, $current['avg_memory']);
        self::assertSame(40.0, $current['avg_wait_ms']);

        // the "all" pseudo-queue aggregates every queue bucket
        $all = $this->storage->getMetrics('all', 'all', 5);
        self::assertSame(2, end($all)['jobs']);
    }

    public function testCounters(): void
    {
        $this->storage->flush(
            [$this->record('a'), $this->record('b', JobRecord::STATUS_FAILED)],
            $this->bucket('async', jobs: 2, failed: 1),
            [],
        );

        $counters = $this->storage->getCounters();
        self::assertSame(2, $counters['jobs_total']);
        self::assertSame(1, $counters['failed_total']);
        self::assertSame(2, $counters['recent_count']);
        self::assertSame(1, $counters['failed_count']);
    }

    public function testHeartbeatsAndStalePruning(): void
    {
        $this->storage->heartbeatWorker('1@host', ['id' => '1@host', 'status' => 'idle']);
        $this->storage->heartbeatWorker('2@host', ['id' => '2@host', 'status' => 'processing']);

        // simulate TTL expiry of worker 2's hash
        unset($this->redis->hashes['horizon:worker:2@host']);

        $workers = $this->storage->getWorkers();
        self::assertSame(['1@host'], array_keys($workers));
        self::assertSame(['1@host'], $this->redis->smembers('horizon:workers'), 'stale member must be pruned from the set');
    }

    public function testSupervisorLifecycle(): void
    {
        $this->storage->heartbeatSupervisor('default', ['name' => 'default', 'status' => 'running']);
        self::assertArrayHasKey('default', $this->storage->getSupervisors());

        $this->storage->removeSupervisor('default');
        self::assertSame([], $this->storage->getSupervisors());
    }

    public function testCommandQueueIsFifo(): void
    {
        $this->storage->pushCommand(StorageInterface::CMD_PAUSE);
        $this->storage->pushCommand(StorageInterface::CMD_CONTINUE);

        self::assertSame('pause', $this->storage->popCommand());
        self::assertSame('continue', $this->storage->popCommand());
        self::assertNull($this->storage->popCommand());
    }

    public function testSnapshotRollup(): void
    {
        $this->storage->flush(
            [$this->record('job1'), $this->record('job2')],
            $this->bucket('async', jobs: 2, durationSum: 50.0),
            [],
        );

        $this->storage->storeSnapshots();

        $snapshots = $this->storage->getSnapshots('q', 'async');
        self::assertCount(1, $snapshots);
        self::assertSame(2, $snapshots[0]['jobs']);
        self::assertSame(25.0, (float) $snapshots[0]['avg_duration_ms']);
    }
}
