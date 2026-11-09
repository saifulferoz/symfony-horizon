<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Realtime;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;

/**
 * Pushes dashboard snapshots to connected viewers on a fixed cadence.
 *
 * Driven by the horizon supervisor's tick (and by horizon:broadcast for
 * deployments whose workers are managed elsewhere), never by the workers
 * themselves: publishing is an HTTP call, and the message-handling path must
 * stay free of it.
 *
 * On a multi-host deployment every instance would otherwise publish the same
 * snapshot each tick, so a short cluster lock elects one publisher per
 * interval. The lock self-expires, so a crashed publisher is replaced on the
 * next tick rather than silencing the dashboard.
 */
final class DashboardBroadcaster
{
    public const TOPIC_STATS = 'stats';
    public const TOPIC_WORKERS = 'workers';

    private const LOCK = 'broadcast';

    private float $lastPublishedAt = 0.0;

    public function __construct(
        private readonly SnapshotBuilder $snapshots,
        private readonly UpdatePublisherInterface $publisher,
        private readonly StorageInterface $storage,
        private readonly string $topicPrefix = 'horizon://dashboard/',
        private readonly int $publishInterval = 1,
    ) {
    }

    /**
     * Publishes if the interval has elapsed and this instance wins the lock.
     *
     * @return bool whether an update was published
     */
    public function tick(): bool
    {
        if (!$this->publisher->isActive()) {
            return false;
        }

        $now = microtime(true);
        if (($now - $this->lastPublishedAt) < $this->publishInterval) {
            return false;
        }
        $this->lastPublishedAt = $now;

        // One publisher per interval across the whole cluster. The TTL matches
        // the interval, so the election repeats every tick rather than pinning
        // the role to whichever instance happened to start first.
        try {
            if (!$this->storage->acquireLock(self::LOCK, max(1, $this->publishInterval))) {
                return false;
            }
        } catch (\Throwable) {
            // Storage unreachable: skip this tick rather than publish N copies.
            return false;
        }

        return $this->publish();
    }

    /**
     * Publishes immediately, bypassing the interval and the lock. Used when a
     * viewer-visible change has just happened (a retry, a delete) and waiting
     * for the next tick would feel broken.
     */
    public function publishNow(): bool
    {
        if (!$this->publisher->isActive()) {
            return false;
        }

        $this->lastPublishedAt = microtime(true);

        return $this->publish();
    }

    private function publish(): bool
    {
        try {
            $this->publisher->publish($this->topicPrefix . self::TOPIC_STATS, $this->snapshots->stats());
            $this->publisher->publish($this->topicPrefix . self::TOPIC_WORKERS, $this->snapshots->workers());
        } catch (\Throwable) {
            // Reading storage can fail; the publisher itself never throws.
            return false;
        }

        return true;
    }
}
