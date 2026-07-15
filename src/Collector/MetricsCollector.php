<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Collector;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Stamp\DispatchedAtStamp;
use Saifulferoz\SymfonyHorizon\Tags\HorizonTags;
use Saifulferoz\SymfonyHorizon\Tags\TaggableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Buffers per-job metrics in memory and flushes them to storage in batches,
 * so consuming a message never pays a synchronous per-message Redis round-trip.
 *
 * Captured per job: duration (hrtime), memory delta, true peak memory
 * (memory_reset_peak_usage), queue wait time (DispatchedAtStamp), attempts.
 */
final class MetricsCollector
{
    private const HEARTBEAT_INTERVAL = 5.0;

    /** @var list<JobRecord> */
    private array $records = [];
    /** @var array<string, array<string, int|float>> keyed "name|YmdHi" */
    private array $queueBuckets = [];
    /** @var array<string, array<string, int|float>> keyed "name|YmdHi" */
    private array $classBuckets = [];
    /** @var array<mixed>|null state of the job currently being handled */
    private ?array $current = null;

    private float $lastFlush;
    private float $lastHeartbeat = 0.0;
    private string $workerId;
    /** @var list<string> */
    private array $transports = [];
    private float $startedAt;
    private int $processedCount = 0;
    private int $failedCount = 0;
    /** @var array<class-string, list<string>> */
    private array $tagCache = [];

    private readonly int $flushBatch;
    private readonly int $flushInterval;
    private readonly float $sampling;
    private readonly bool $capturePayload;
    private readonly int $payloadMaxBytes;

    /**
     * @param array{flush_batch?: int, flush_interval?: int, sampling?: float, capture_payload?: bool, payload_max_bytes?: int} $config
     */
    public function __construct(
        private readonly StorageInterface $storage,
        array $config = [],
    ) {
        $this->flushBatch = $config['flush_batch'] ?? 25;
        $this->flushInterval = $config['flush_interval'] ?? 3;
        $this->sampling = $config['sampling'] ?? 1.0;
        $this->capturePayload = $config['capture_payload'] ?? false;
        $this->payloadMaxBytes = $config['payload_max_bytes'] ?? 10240;

        $this->workerId = getmypid() . '@' . (gethostname() ?: 'unknown');
        $this->lastFlush = microtime(true);
        $this->startedAt = microtime(true);
    }

    /**
     * @param list<string> $transports
     */
    public function workerStarted(array $transports): void
    {
        $this->transports = $transports;
        $this->startedAt = microtime(true);
        $this->heartbeat('idle');
    }

    public function jobReceived(Envelope $envelope, string $receiverName): void
    {
        $message = $envelope->getMessage();

        $waitMs = null;
        $dispatched = $envelope->last(DispatchedAtStamp::class);
        if ($dispatched instanceof DispatchedAtStamp) {
            $waitMs = max(0.0, (microtime(true) - $dispatched->dispatchedAt) * 1000);
        }

        $redelivery = $envelope->last(RedeliveryStamp::class);
        $transportId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        $this->current = [
            'id' => bin2hex(random_bytes(9)),
            'class' => $message::class,
            'queue' => $receiverName,
            'transport_id' => $transportId !== null ? (string) $transportId : null,
            'wait_ms' => $waitMs,
            'attempts' => ($redelivery instanceof RedeliveryStamp ? $redelivery->getRetryCount() : 0) + 1,
            'received_at' => microtime(true),
        ];

        // Do these last so the collector's own bookkeeping is excluded from the job's numbers.
        if (\function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $this->current['mem_start'] = memory_get_usage(true);
        $this->current['start'] = hrtime(true);
    }

    public function jobHandled(Envelope $envelope): void
    {
        $this->finalize($envelope, JobRecord::STATUS_COMPLETED);
    }

    public function jobFailed(Envelope $envelope, \Throwable $error, bool $willRetry): void
    {
        $this->finalize($envelope, $willRetry ? JobRecord::STATUS_RELEASED : JobRecord::STATUS_FAILED, $error);
    }

    /**
     * Called on WorkerRunningEvent: flush when the batch/interval is due (always
     * when idle, since no further messages will push the buffer over the limit).
     */
    public function workerRunning(bool $idle): void
    {
        $this->maybeFlush($idle);

        $now = microtime(true);
        if (($now - $this->lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
            $this->heartbeat($idle ? 'idle' : 'processing');
        }
    }

    public function workerStopped(): void
    {
        $this->maybeFlush(true);
        $this->storage->removeWorker($this->workerId);
    }

    private function finalize(Envelope $envelope, string $status, ?\Throwable $error = null): void
    {
        $current = $this->current;
        $this->current = null;
        if ($current === null) {
            return;
        }

        $durationMs = (hrtime(true) - $current['start']) / 1e6;
        $peakMemory = memory_get_peak_usage(true);
        $memoryUsed = max(0, memory_get_usage(true) - $current['mem_start']);
        $finishedAt = microtime(true);
        $failed = $status !== JobRecord::STATUS_COMPLETED;

        if ($failed) {
            ++$this->failedCount;
        } else {
            ++$this->processedCount;
        }

        $minute = gmdate('YmdHi', (int) $finishedAt);
        $this->bumpBucket($this->queueBuckets, $current['queue'] . '|' . $minute, $failed, $durationMs, $peakMemory, $current['wait_ms']);
        $this->bumpBucket($this->classBuckets, $current['class'] . '|' . $minute, $failed, $durationMs, $peakMemory, $current['wait_ms']);

        // Failures are always recorded in detail; completed jobs honour the sampling rate.
        if ($failed || $this->sampling >= 1.0 || mt_rand() / mt_getrandmax() < $this->sampling) {
            $message = $envelope->getMessage();

            $payload = null;
            if ($this->capturePayload || $status === JobRecord::STATUS_FAILED) {
                $payload = $this->serializePayload($message);
            }

            $retryPayload = null;
            if ($status === JobRecord::STATUS_FAILED) {
                try {
                    $retryPayload = base64_encode(serialize($message));
                } catch (\Throwable) {
                    // Message not serializable (closures, resources); retry from dashboard unavailable.
                }
            }

            $this->records[] = new JobRecord(
                id: $current['id'],
                class: $current['class'],
                queue: $current['queue'],
                status: $status,
                durationMs: $durationMs,
                memoryUsed: $memoryUsed,
                peakMemory: $peakMemory,
                waitMs: $current['wait_ms'],
                attempts: $current['attempts'],
                workerId: $this->workerId,
                receivedAt: $current['received_at'],
                finishedAt: $finishedAt,
                tags: $this->tagsFor($message),
                payload: $payload,
                exceptionClass: $error !== null ? $error::class : null,
                exceptionMessage: $error !== null ? mb_substr($error->getMessage(), 0, 2000) : null,
                exceptionTrace: $error !== null ? mb_substr($error->getTraceAsString(), 0, 20000) : null,
                retryPayload: $retryPayload,
            );
        }

        $this->maybeFlush(false);
    }

    /**
     * @param array<string, array<string, int|float>> $buckets
     */
    private function bumpBucket(array &$buckets, string $key, bool $failed, float $durationMs, int $peakMemory, ?float $waitMs): void
    {
        $bucket = &$buckets[$key];
        $bucket ??= ['jobs' => 0, 'failed' => 0, 'duration_sum' => 0.0, 'memory_sum' => 0, 'wait_sum' => 0.0, 'wait_count' => 0];

        ++$bucket['jobs'];
        if ($failed) {
            ++$bucket['failed'];
        }
        $bucket['duration_sum'] += $durationMs;
        $bucket['memory_sum'] += $peakMemory;
        if ($waitMs !== null) {
            $bucket['wait_sum'] += $waitMs;
            ++$bucket['wait_count'];
        }
    }

    private function maybeFlush(bool $force): void
    {
        $due = $force
            || \count($this->records) >= $this->flushBatch
            || (microtime(true) - $this->lastFlush) >= $this->flushInterval;

        if (!$due) {
            return;
        }

        if ($this->records !== [] || $this->queueBuckets !== []) {
            $this->storage->flush($this->records, $this->queueBuckets, $this->classBuckets);
            $this->records = [];
            $this->queueBuckets = [];
            $this->classBuckets = [];
        }

        $this->lastFlush = microtime(true);
    }

    private function heartbeat(string $status): void
    {
        $this->lastHeartbeat = microtime(true);

        $this->storage->heartbeatWorker($this->workerId, [
            'id' => $this->workerId,
            'pid' => (string) getmypid(),
            'host' => gethostname() ?: 'unknown',
            'transports' => implode(',', $this->transports),
            'status' => $status,
            'current_class' => $this->current['class'] ?? '',
            'processed' => (string) $this->processedCount,
            'failed' => (string) $this->failedCount,
            'memory' => (string) memory_get_usage(true),
            'started_at' => (string) $this->startedAt,
        ]);
    }

    private function serializePayload(object $message): string
    {
        try {
            $payload = json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable) {
            $payload = false;
        }

        if ($payload === false || $payload === '{}') {
            // json_encode only sees public properties; fall back to a readable dump.
            try {
                $payload = substr(print_r($message, true), 0, $this->payloadMaxBytes);
            } catch (\Throwable) {
                return $message::class;
            }
        }

        return substr((string) $payload, 0, $this->payloadMaxBytes);
    }

    /**
     * @return list<string>
     */
    private function tagsFor(object $message): array
    {
        $class = $message::class;

        if (!isset($this->tagCache[$class])) {
            $tags = [];
            foreach ((new \ReflectionClass($class))->getAttributes(HorizonTags::class) as $attribute) {
                $tags = $attribute->newInstance()->tags;
            }
            $this->tagCache[$class] = $tags;
        }

        $tags = $this->tagCache[$class];
        if ($message instanceof TaggableInterface) {
            $tags = array_values(array_unique([...$tags, ...$message->horizonTags()]));
        }

        return $tags;
    }
}
