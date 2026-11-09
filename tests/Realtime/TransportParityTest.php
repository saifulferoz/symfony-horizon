<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Controller\ApiController;
use Saifulferoz\SymfonyHorizon\Realtime\DashboardBroadcaster;
use Saifulferoz\SymfonyHorizon\Realtime\SnapshotBuilder;
use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Storage\RedisStorage;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\FakeRedis;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The dashboard is fed by polling OR by push, and a viewer must not be able to
 * tell which. If the two ever produce different payloads, the UI misbehaves in
 * exactly one mode — the kind of bug that is miserable to track down.
 */
final class TransportParityTest extends TestCase
{
    public function testPolledAndPushedPayloadsAreIdentical(): void
    {
        $storage = new RedisStorage(new FakeRedis(), 'horizon:');
        $storage->heartbeatWorker('1@host', ['id' => '1@host', 'status' => 'idle', 'processed' => '7']);

        $snapshots = new SnapshotBuilder($storage);

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps);
            }
        };
        $api = new ApiController($storage, new FailedJobRetryer($storage, $bus), $snapshots);

        $publisher = new SpyPublisher();
        (new DashboardBroadcaster($snapshots, $publisher, $storage, 'horizon://dashboard/', 1))->publishNow();

        $polledStats = json_decode((string) $api->stats()->getContent(), true);
        $polledWorkers = json_decode((string) $api->workers()->getContent(), true);

        $pushed = [];
        foreach ($publisher->published as $update) {
            $pushed[$update['topic']] = $update['payload'];
        }

        // Compare through JSON: that is the form the browser actually receives.
        self::assertSame(
            json_encode($polledStats),
            json_encode($pushed['horizon://dashboard/stats']),
            'the stats payload must be identical over both transports',
        );
        self::assertSame(
            json_encode($polledWorkers),
            json_encode($pushed['horizon://dashboard/workers']),
            'the workers payload must be identical over both transports',
        );
    }
}
