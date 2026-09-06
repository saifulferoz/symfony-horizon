<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Records the dispatch time as a plain scalar header so workers can report how
 * long a message waited in the queue.
 *
 * Deliberately NOT a Messenger stamp: a stamp is serialized as
 * "X-Message-Stamp-<FQCN>" and Messenger refuses to decode a message whose
 * stamp class it cannot load. That would make every message produced with this
 * bundle undecodable by any consumer without it — a poisoned queue during
 * partial rollouts, or for a second service reading the same transport.
 *
 * A scalar header is ignored by consumers that do not know about it, so the
 * bundle never becomes a wire-format dependency.
 */
final class WaitTimeSerializer implements SerializerInterface
{
    public const HEADER = 'X-Horizon-Dispatched-At';

    public function __construct(private readonly SerializerInterface $inner)
    {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $envelope = $this->inner->decode($encodedEnvelope);

        $dispatchedAt = $encodedEnvelope['headers'][self::HEADER] ?? null;
        if (!\is_string($dispatchedAt) && !\is_float($dispatchedAt) && !\is_int($dispatchedAt)) {
            return $envelope;
        }

        $timestamp = (float) $dispatchedAt;
        if ($timestamp <= 0.0) {
            return $envelope;
        }

        // Attached as a non-sendable stamp: it exists only in-process for the
        // collector to read, and can never be written back to a transport.
        return $envelope->with(new DispatchedAt($timestamp));
    }

    public function encode(Envelope $envelope): array
    {
        $encoded = $this->inner->encode($envelope);

        $encoded['headers'] ??= [];
        $encoded['headers'][self::HEADER] = (string) microtime(true);

        return $encoded;
    }
}
