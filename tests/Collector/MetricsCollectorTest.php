<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Collector;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Collector\JobRecord;
use Saifulferoz\SymfonyHorizon\Collector\MetricsCollector;
use Saifulferoz\SymfonyHorizon\Stamp\DispatchedAtStamp;
use Saifulferoz\SymfonyHorizon\Tags\HorizonTags;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

#[HorizonTags('reports', 'critical')]
final class TaggedMessage
{
    public function __construct(public string $name = 'demo')
    {
    }
}

final class MetricsCollectorTest extends TestCase
{
    private SpyStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new SpyStorage();
    }

    private function collector(array $config = []): MetricsCollector
    {
        return new MetricsCollector($this->storage, $config + [
            'flush_batch' => 3,
            'flush_interval' => 9999, // isolate the batch-size trigger
            'sampling' => 1.0,
        ]);
    }

    private function completeOneJob(MetricsCollector $collector, ?Envelope $envelope = null): void
    {
        $envelope ??= new Envelope(new TaggedMessage());
        $collector->jobReceived($envelope, 'async');
        $collector->jobHandled($envelope);
    }

    public function testNothingIsFlushedBeforeTheBatchIsFull(): void
    {
        $collector = $this->collector();

        $this->completeOneJob($collector);
        $this->completeOneJob($collector);

        self::assertSame([], $this->storage->flushes, 'buffered records must not hit storage before the batch is full');
    }

    public function testBatchSizeTriggersASingleFlush(): void
    {
        $collector = $this->collector();

        $this->completeOneJob($collector);
        $this->completeOneJob($collector);
        $this->completeOneJob($collector);

        self::assertCount(1, $this->storage->flushes);
        self::assertCount(3, $this->storage->flushes[0]['records']);
    }

    public function testIdleWorkerFlushesPendingRecords(): void
    {
        $collector = $this->collector();

        $this->completeOneJob($collector);
        $collector->workerRunning(idle: true);

        self::assertCount(1, $this->storage->flushes);
        self::assertCount(1, $this->storage->flushes[0]['records']);
    }

    public function testCapturesDurationMemoryAndTags(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage());

        $collector->jobReceived($envelope, 'async');
        usleep(15_000);
        $waste = str_repeat('x', 2_000_000); // force measurable peak memory
        $collector->jobHandled($envelope);
        unset($waste);

        $collector->workerRunning(idle: true);

        $record = $this->storage->flushes[0]['records'][0];
        self::assertInstanceOf(JobRecord::class, $record);
        self::assertSame(JobRecord::STATUS_COMPLETED, $record->status);
        self::assertSame(TaggedMessage::class, $record->class);
        self::assertSame('async', $record->queue);
        self::assertGreaterThan(10.0, $record->durationMs, 'duration should cover the 15ms sleep');
        self::assertGreaterThan(1_000_000, $record->peakMemory, 'peak memory should include the 2MB allocation');
        self::assertSame(['reports', 'critical'], $record->tags);
        self::assertSame(1, $record->attempts);
    }

    public function testComputesQueueWaitFromDispatchedAtStamp(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage(), [new DispatchedAtStamp(microtime(true) - 0.25)]);

        $this->completeOneJob($collector, $envelope);
        $collector->workerRunning(idle: true);

        $record = $this->storage->flushes[0]['records'][0];
        self::assertNotNull($record->waitMs);
        self::assertGreaterThan(200.0, $record->waitMs);
    }

    public function testFailedJobKeepsExceptionAndRetryPayload(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage('boom'), [new RedeliveryStamp(2)]);

        $collector->jobReceived($envelope, 'async');
        $collector->jobFailed($envelope, new \RuntimeException('handler exploded'), willRetry: false);
        $collector->workerRunning(idle: true);

        $record = $this->storage->flushes[0]['records'][0];
        self::assertSame(JobRecord::STATUS_FAILED, $record->status);
        self::assertSame(\RuntimeException::class, $record->exceptionClass);
        self::assertSame('handler exploded', $record->exceptionMessage);
        self::assertSame(3, $record->attempts);
        self::assertNotNull($record->payload, 'failed jobs always store a payload');
        self::assertNotNull($record->retryPayload);
        self::assertInstanceOf(TaggedMessage::class, unserialize(base64_decode($record->retryPayload, true) ?: ''));
    }

    public function testWillRetryFailureIsRecordedAsReleasedWithoutRetryPayload(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage());

        $collector->jobReceived($envelope, 'async');
        $collector->jobFailed($envelope, new \RuntimeException('transient'), willRetry: true);
        $collector->workerRunning(idle: true);

        $record = $this->storage->flushes[0]['records'][0];
        self::assertSame(JobRecord::STATUS_RELEASED, $record->status);
        self::assertNull($record->retryPayload, 'messenger retries released jobs itself');
    }

    public function testSamplingSkipsRecordsButBucketsCountEveryJob(): void
    {
        $collector = $this->collector(['sampling' => 0.0]);

        $this->completeOneJob($collector);
        $this->completeOneJob($collector);
        $collector->workerRunning(idle: true);

        self::assertCount(1, $this->storage->flushes);
        $flush = $this->storage->flushes[0];
        self::assertSame([], $flush['records']);

        $bucket = array_values($flush['queueBuckets'])[0];
        self::assertSame(2, $bucket['jobs']);

        $bucketKey = array_keys($flush['queueBuckets'])[0];
        self::assertStringStartsWith('async|', $bucketKey);
    }

    public function testFailuresAreAlwaysRecordedEvenWhenSampledOut(): void
    {
        $collector = $this->collector(['sampling' => 0.0]);
        $envelope = new Envelope(new TaggedMessage());

        $collector->jobReceived($envelope, 'async');
        $collector->jobFailed($envelope, new \RuntimeException('nope'), willRetry: false);
        $collector->workerRunning(idle: true);

        self::assertCount(1, $this->storage->flushes[0]['records']);
    }

    public function testWorkerLifecycleHeartbeats(): void
    {
        $collector = $this->collector();

        $collector->workerStarted(['async', 'priority']);
        self::assertCount(1, $this->storage->workerHeartbeats);
        [$workerId, $meta] = $this->storage->workerHeartbeats[0];
        self::assertSame('async,priority', $meta['transports']);
        self::assertSame('idle', $meta['status']);

        $collector->workerStopped();
        self::assertSame([$workerId], $this->storage->removedWorkers);
    }
}
