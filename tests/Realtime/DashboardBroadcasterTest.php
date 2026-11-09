<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Realtime\DashboardBroadcaster;
use Saifulferoz\SymfonyHorizon\Realtime\NullUpdatePublisher;
use Saifulferoz\SymfonyHorizon\Realtime\SnapshotBuilder;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\BrokenStorage;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;

final class DashboardBroadcasterTest extends TestCase
{
    private SpyStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new SpyStorage();
    }

    private function broadcaster(SpyPublisher|NullUpdatePublisher $publisher, int $interval = 1): DashboardBroadcaster
    {
        return new DashboardBroadcaster(
            new SnapshotBuilder($this->storage),
            $publisher,
            $this->storage,
            'horizon://dashboard/',
            $interval,
        );
    }

    public function testPublishesStatsAndWorkersOnTheConfiguredTopics(): void
    {
        $publisher = new SpyPublisher();

        self::assertTrue($this->broadcaster($publisher)->publishNow());
        self::assertSame(
            ['horizon://dashboard/stats', 'horizon://dashboard/workers'],
            $publisher->topics(),
        );
    }

    public function testOnlyOneInstancePublishesPerIntervalAcrossTheCluster(): void
    {
        // Several hosts run the supervisor against one storage; whoever loses
        // the lock must stay silent or every viewer gets N copies.
        $publisher = new SpyPublisher();
        $this->storage->lockGranted = false;

        self::assertFalse($this->broadcaster($publisher)->tick());
        self::assertSame([], $publisher->published);
        self::assertSame(['broadcast'], $this->storage->lockAttempts);
    }

    public function testTickRespectsThePublishInterval(): void
    {
        $publisher = new SpyPublisher();
        $broadcaster = $this->broadcaster($publisher, interval: 3600);

        self::assertTrue($broadcaster->tick(), 'the first tick publishes');
        self::assertFalse($broadcaster->tick(), 'a tick inside the interval must not republish');
        self::assertCount(2, $publisher->published, 'still just the one stats + workers pair');
    }

    public function testPollingModeCostsNothing(): void
    {
        // The broadcaster is wired unconditionally, so in polling mode it must
        // not even read storage, let alone take the cluster lock.
        $broadcaster = $this->broadcaster(new NullUpdatePublisher());

        self::assertFalse($broadcaster->tick());
        self::assertFalse($broadcaster->publishNow());
        self::assertSame([], $this->storage->lockAttempts);
    }

    public function testAStorageFailureSkipsTheTickInsteadOfThrowing(): void
    {
        // Publishing happens inside the supervisor loop; a storage blip must
        // never take the supervisor down.
        $publisher = new SpyPublisher();
        $storage = new BrokenStorage();
        $broadcaster = new DashboardBroadcaster(
            new SnapshotBuilder($storage),
            $publisher,
            $storage,
            'horizon://dashboard/',
            1,
        );

        self::assertFalse($broadcaster->tick());
        self::assertSame([], $publisher->published);
    }
}
