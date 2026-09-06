<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Collector;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Collector\JobRecord;
use Saifulferoz\SymfonyHorizon\Collector\MetricsCollector;
use Saifulferoz\SymfonyHorizon\Tags\HorizonTags;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\BrokenStorage;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Saifulferoz\SymfonyHorizon\Transport\DispatchedAt;
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

    /**
     * @param array<string, mixed> $config
     */
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

    public function testComputesQueueWaitFromTheTransportHeader(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage(), [new DispatchedAt(microtime(true) - 0.25)]);

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

        // A released attempt is neither a failure nor completed work: it is
        // counted as a retry, so throughput and the failed metric both stay
        // consistent with what the dashboard lists.
        $bucket = array_values($this->storage->flushes[0]['queueBuckets'])[0];
        self::assertSame(0, $bucket['failed']);
        self::assertSame(0, $bucket['jobs'], 'a retried attempt is not throughput');
        self::assertSame(1, $bucket['retries']);
    }

    public function testFinalFailureCountsInFailedBucket(): void
    {
        $collector = $this->collector();
        $envelope = new Envelope(new TaggedMessage());

        $collector->jobReceived($envelope, 'async');
        $collector->jobFailed($envelope, new \RuntimeException('fatal'), willRetry: false);
        $collector->workerRunning(idle: true);

        $bucket = array_values($this->storage->flushes[0]['queueBuckets'])[0];
        self::assertSame(1, $bucket['failed']);
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

    // --- storage-outage resilience -------------------------------------
    // Metrics collection must never be able to break message consumption.

    public function testStorageFailureDoesNotEscapeIntoTheWorkerLoop(): void
    {
        $broken = new BrokenStorage();
        $collector = new MetricsCollector($broken, ['flush_batch' => 1, 'flush_interval' => 9999]);
        $envelope = new Envelope(new TaggedMessage());

        $collector->workerStarted(['async']);
        for ($i = 0; $i < 5; ++$i) {
            $collector->jobReceived($envelope, 'async');
            $collector->jobHandled($envelope);
        }
        $collector->workerRunning(idle: true);
        $collector->workerStopped();

        // Reaching here at all is the assertion: nothing propagated.
        self::assertGreaterThan(0, $broken->flushAttempts, 'storage was actually exercised');
    }

    public function testFailedFlushDoesNotRetainRecords(): void
    {
        $broken = new BrokenStorage();
        $collector = new MetricsCollector($broken, ['flush_batch' => 2, 'flush_interval' => 9999]);
        $envelope = new Envelope(new TaggedMessage());

        for ($i = 0; $i < 10; ++$i) {
            $collector->jobReceived($envelope, 'async');
            $collector->jobHandled($envelope);
        }

        $records = new \ReflectionProperty(MetricsCollector::class, 'records');
        self::assertSame([], $records->getValue($collector), 'a failing flush must not keep buffering records');
    }

    public function testStorageFailureIsLogged(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0: string, 1: string}> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message];
            }
        };

        $collector = new MetricsCollector(new BrokenStorage(), ['flush_batch' => 1, 'flush_interval' => 9999], $logger);
        $envelope = new Envelope(new TaggedMessage());
        $collector->jobReceived($envelope, 'async');
        $collector->jobHandled($envelope);

        self::assertNotSame([], $logger->logged, 'a dropped metrics batch must be visible in the logs');
        self::assertSame('warning', $logger->logged[0][0]);
    }

    public function testBufferIsCappedWhileStorageIsDown(): void
    {
        // flush_batch high enough that the batch trigger never fires, so only
        // the hard ceiling can bound the buffer.
        $collector = new MetricsCollector(new BrokenStorage(), ['flush_batch' => 100000, 'flush_interval' => 9999]);
        $envelope = new Envelope(new TaggedMessage());

        for ($i = 0; $i < 1200; ++$i) {
            $collector->jobReceived($envelope, 'async');
            $collector->jobHandled($envelope);
        }

        $records = new \ReflectionProperty(MetricsCollector::class, 'records');
        self::assertLessThanOrEqual(1000, \count($records->getValue($collector)), 'buffer must not grow without bound');
    }

    public function testRetryStormDoesNotInflateThroughput(): void
    {
        $collector = $this->collector(['flush_batch' => 100]);
        $envelope = new Envelope(new TaggedMessage());

        // one success, plus one message that fails three times then finally fails
        $this->completeOneJob($collector);
        for ($i = 0; $i < 3; ++$i) {
            $collector->jobReceived($envelope, 'async');
            $collector->jobFailed($envelope, new \RuntimeException('transient'), willRetry: true);
        }
        $collector->jobReceived($envelope, 'async');
        $collector->jobFailed($envelope, new \RuntimeException('fatal'), willRetry: false);
        $collector->workerRunning(idle: true);

        $bucket = array_values($this->storage->flushes[0]['queueBuckets'])[0];
        self::assertSame(2, $bucket['jobs'], 'two messages reached a final outcome');
        self::assertSame(1, $bucket['failed']);
        self::assertSame(3, $bucket['retries'], 'retries are tracked separately');
    }
}
