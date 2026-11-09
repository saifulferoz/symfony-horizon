<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Realtime;

/**
 * Pushes a dashboard payload to connected viewers.
 *
 * This seam keeps Mercure an optional dependency: nothing outside
 * MercureUpdatePublisher references symfony/mercure, so the bundle boots and
 * the dashboard works whether or not it is installed.
 */
interface UpdatePublisherInterface
{
    /**
     * Must never throw: a failing transport may degrade the dashboard to
     * polling, but it may not break the process doing the publishing.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $topic, array $payload): void;

    /**
     * Whether updates actually reach subscribers. False for the null
     * implementation, so callers can skip building payloads they cannot send.
     */
    public function isActive(): bool;
}
