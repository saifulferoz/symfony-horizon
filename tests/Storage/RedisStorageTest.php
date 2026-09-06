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
    /** Effective key prefix: configured prefix + schema version segment. */
    private const KEY = 'horizon:v1:';

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

        self::assertSame('completed', $this->redis->hashes[self::KEY . 'job:job1']['status']);
        self::assertSame('failed', $this->redis->hashes[self::KEY . 'job:job2']['status']);
        self::assertSame('boom', $this->redis->hashes[self::KEY . 'job:job2']['exception_message']);

        self::assertArrayHasKey('job1', $this->redis->zsets[self::KEY . 'jobs:recent']);
        self::assertArrayHasKey('job2', $this->redis->zsets[self::KEY . 'jobs:recent']);
        self::assertArrayHasKey('job2', $this->redis->zsets[self::KEY . 'jobs:failed']);
        self::assertArrayNotHasKey('job1', $this->redis->zsets[self::KEY . 'jobs:failed'] ?? []);

        self::assertSame('2', $this->redis->strings[self::KEY . 'stats:jobs']);
        self::assertSame('1', $this->redis->strings[self::KEY . 'stats:failed']);
        self::assertSame(['async'], $this->redis->smembers(self::KEY . 'queues'));
        self::assertSame(['App\\Message\\Demo'], $this->redis->smembers(self::KEY . 'classes'));

        // every job hash gets a TTL so Redis stays bounded
        self::assertArrayHasKey(self::KEY . 'job:job1', $this->redis->ttls);
        self::assertArrayHasKey(self::KEY . 'job:job2', $this->redis->ttls);
        self::assertGreaterThan($this->redis->ttls[self::KEY . 'job:job1'], $this->redis->ttls[self::KEY . 'job:job2'], 'failed jobs are kept longer');
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
        unset($this->redis->hashes[self::KEY . 'worker:2@host']);

        $workers = $this->storage->getWorkers();
        self::assertSame(['1@host'], array_keys($workers));
        self::assertSame(['1@host'], $this->redis->smembers(self::KEY . 'workers'), 'stale member must be pruned from the set');
    }

    public function testSupervisorLifecycle(): void
    {
        $this->storage->heartbeatSupervisor('default', ['name' => 'default', 'status' => 'running']);
        self::assertArrayHasKey('default', $this->storage->getSupervisors());

        $this->storage->removeSupervisor('default');
        self::assertSame([], $this->storage->getSupervisors());
    }

    public function testControlCommandIsBroadcastStateNotAQueue(): void
    {
        self::assertNull($this->storage->readCommand(), 'no command issued yet');

        $this->storage->pushCommand(StorageInterface::CMD_PAUSE);

        $first = $this->storage->readCommand();
        self::assertSame(StorageInterface::CMD_PAUSE, $first['command']);

        // Reading must NOT consume: every horizon instance has to see it.
        $second = $this->storage->readCommand();
        self::assertSame($first, $second, 'the command must survive being read');

        // A newer command supersedes the previous one with a higher version.
        $this->storage->pushCommand(StorageInterface::CMD_CONTINUE);
        $next = $this->storage->readCommand();
        self::assertSame(StorageInterface::CMD_CONTINUE, $next['command']);
        self::assertGreaterThan($first['version'], $next['version'], 'versions increase monotonically');
    }

    public function testLockIsGrantedOnlyOnce(): void
    {
        self::assertTrue($this->storage->acquireLock('snapshot', 60), 'first caller wins');
        self::assertFalse($this->storage->acquireLock('snapshot', 60), 'a second instance must be refused');
        self::assertTrue($this->storage->acquireLock('other', 60), 'unrelated locks are independent');
    }

    public function testLegacyKeysFromAnIncompatibleLayoutAreIgnored(): void
    {
        // The pre-rewrite bundle stored these as lists at the unversioned keys.
        // ZADD against a list fails with WRONGTYPE, which previously left the
        // job indexes silently empty; the version segment must keep them apart.
        $this->redis->lists['horizon:jobs:recent'] = ['old1', 'old2'];
        $this->redis->lists['horizon:jobs:failed'] = ['old3'];
        $this->redis->strings['horizon:stats:total_jobs'] = '999';

        $this->storage->flush([$this->record('fresh')], $this->bucket('async'), []);

        // New data lands under the versioned namespace...
        self::assertArrayHasKey(self::KEY . 'jobs:recent', $this->redis->zsets);
        self::assertSame(1, $this->storage->getRecentJobs()['total']);

        // ...and legacy keys are neither read nor destroyed; they age out via TTL.
        self::assertSame(['old1', 'old2'], $this->redis->lists['horizon:jobs:recent']);
        self::assertSame('999', $this->redis->strings['horizon:stats:total_jobs']);
    }

    public function testPageJobsSkipsExpiredPayloadsAndSelfHealsTheIndex(): void
    {
        $now = microtime(true);
        $records = [];
        for ($i = 0; $i < 5; ++$i) {
            $records[] = $this->record('j' . $i, finishedAt: $now + $i);
        }
        $this->storage->flush($records, $this->bucket('async', jobs: 5), []);

        // Three job hashes reach their TTL while their ids remain in the index.
        foreach (['j0', 'j1', 'j2'] as $id) {
            unset($this->redis->hashes[self::KEY . 'job:' . $id]);
        }

        $page = $this->storage->getRecentJobs(50);

        self::assertCount(2, $page['jobs'], 'only fetchable jobs are returned');
        self::assertSame(2, $page['total'], 'total must match what can actually be paged through');
        self::assertCount(2, $this->redis->zsets[self::KEY . 'jobs:recent'], 'dangling ids are pruned');

        // Stable on repeat: the index has healed, so the same page comes back.
        $again = $this->storage->getRecentJobs(50);
        self::assertCount(2, $again['jobs']);
        self::assertSame(2, $again['total']);
    }

    public function testPageJobsFillsAFullPageDespiteExpiredPayloads(): void
    {
        $now = microtime(true);
        $records = [];
        for ($i = 0; $i < 6; ++$i) {
            $records[] = $this->record('k' . $i, finishedAt: $now + $i);
        }
        $this->storage->flush($records, $this->bucket('async', jobs: 6), []);

        // Expire the two newest, which would otherwise consume the whole page.
        unset($this->redis->hashes[self::KEY . 'job:k5'], $this->redis->hashes[self::KEY . 'job:k4']);

        $page = $this->storage->getRecentJobs(2);

        self::assertCount(2, $page['jobs'], 'the page is filled from further down the index');
        self::assertSame(['k3', 'k2'], array_column($page['jobs'], 'id'));
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
