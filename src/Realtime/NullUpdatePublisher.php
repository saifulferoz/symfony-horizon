<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Realtime;

/**
 * Default publisher: does nothing. Used whenever the dashboard is in polling
 * mode, so the broadcaster can be wired unconditionally without any runtime
 * cost or Mercure dependency.
 */
final class NullUpdatePublisher implements UpdatePublisherInterface
{
    public function publish(string $topic, array $payload): void
    {
    }

    public function isActive(): bool
    {
        return false;
    }
}
