<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\EventListener\DispatchedAtListener;
use Saifulferoz\SymfonyHorizon\Stamp\DispatchedAtStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

final class DispatchedAtListenerTest extends TestCase
{
    public function testStampsOutgoingAsyncMessages(): void
    {
        $event = new SendMessageToTransportsEvent(new Envelope(new \stdClass()), []);

        $before = microtime(true);
        (new DispatchedAtListener())->onSend($event);

        $stamp = $event->getEnvelope()->last(DispatchedAtStamp::class);
        self::assertNotNull($stamp);
        self::assertGreaterThanOrEqual($before, $stamp->dispatchedAt);
        self::assertLessThanOrEqual(microtime(true), $stamp->dispatchedAt);
    }
}
