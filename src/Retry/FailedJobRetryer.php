<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Retry;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Re-dispatches a failed job from the dashboard. The original message object
 * is stored PHP-serialized in the failed-job record; TransportNamesStamp
 * routes it back to the transport it originally failed on.
 */
final class FailedJobRetryer
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws \RuntimeException when the job is unknown or cannot be retried
     */
    public function retry(string $jobId): void
    {
        $job = $this->storage->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Job "%s" was not found (it may have been trimmed).', $jobId));
        }

        $payload = $job['retry_payload'] ?? '';
        if ($payload === '') {
            throw new \RuntimeException(sprintf('Job "%s" has no retryable payload stored.', $jobId));
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new \RuntimeException(sprintf('Job "%s" has a corrupted retry payload.', $jobId));
        }

        try {
            $message = @unserialize($decoded);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Could not unserialize the message of job "%s": %s', $jobId, $e->getMessage()), 0, $e);
        }

        if (!\is_object($message)) {
            throw new \RuntimeException(sprintf('Job "%s" did not unserialize to a message object.', $jobId));
        }

        $stamps = [];
        if (($job['queue'] ?? '') !== '') {
            $stamps[] = new TransportNamesStamp([$job['queue']]);
        }

        $this->bus->dispatch($message, $stamps);
        $this->storage->markJobRetried($jobId);
    }
}
