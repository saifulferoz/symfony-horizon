<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Collector;

final readonly class JobRecord
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RELEASED = 'released'; // failed but will be retried by messenger

    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $class,
        public string $queue,
        public string $status,
        public float $durationMs,
        public int $memoryUsed,
        public int $peakMemory,
        public ?float $waitMs,
        public int $attempts,
        public string $workerId,
        public float $receivedAt,
        public float $finishedAt,
        public array $tags = [],
        public ?string $payload = null,
        public ?string $exceptionClass = null,
        public ?string $exceptionMessage = null,
        public ?string $exceptionTrace = null,
        public ?string $retryPayload = null,
    ) {
    }

    /**
     * Flat string map suitable for a Redis hash.
     *
     * @return array<string, string>
     */
    public function toRow(): array
    {
        $row = [
            'id' => $this->id,
            'class' => $this->class,
            'queue' => $this->queue,
            'status' => $this->status,
            'duration_ms' => (string) round($this->durationMs, 3),
            'memory_used' => (string) $this->memoryUsed,
            'peak_memory' => (string) $this->peakMemory,
            'attempts' => (string) $this->attempts,
            'worker_id' => $this->workerId,
            'received_at' => (string) $this->receivedAt,
            'finished_at' => (string) $this->finishedAt,
        ];

        if ($this->waitMs !== null) {
            $row['wait_ms'] = (string) round($this->waitMs, 3);
        }
        if ($this->tags !== []) {
            $row['tags'] = json_encode($this->tags, \JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        if ($this->payload !== null) {
            $row['payload'] = $this->payload;
        }
        if ($this->exceptionClass !== null) {
            $row['exception_class'] = $this->exceptionClass;
            $row['exception_message'] = (string) $this->exceptionMessage;
            $row['exception_trace'] = (string) $this->exceptionTrace;
        }
        if ($this->retryPayload !== null) {
            $row['retry_payload'] = $this->retryPayload;
        }

        return $row;
    }
}
