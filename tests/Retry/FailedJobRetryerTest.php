<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Retry;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class RetryableMessage
{
    public function __construct(public int $orderId = 42)
    {
    }
}

final class SpyBus implements MessageBusInterface
{
    public ?Envelope $dispatched = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->dispatched = Envelope::wrap($message, $stamps);
    }
}

final class FailedJobRetryerTest extends TestCase
{
    private SpyStorage $storage;
    private SpyBus $bus;
    private FailedJobRetryer $retryer;

    protected function setUp(): void
    {
        $this->storage = new SpyStorage();
        $this->bus = new SpyBus();
        $this->retryer = new FailedJobRetryer($this->storage, $this->bus);
    }

    public function testRetryRedispatchesToTheOriginalTransport(): void
    {
        $this->storage->jobs['job1'] = [
            'id' => 'job1',
            'queue' => 'async_priority',
            'retry_payload' => base64_encode(serialize(new RetryableMessage(7))),
        ];

        $this->retryer->retry('job1');

        self::assertNotNull($this->bus->dispatched);
        $message = $this->bus->dispatched->getMessage();
        self::assertInstanceOf(RetryableMessage::class, $message);
        self::assertSame(7, $message->orderId);

        $stamp = $this->bus->dispatched->last(TransportNamesStamp::class);
        self::assertNotNull($stamp);
        self::assertSame(['async_priority'], $stamp->getTransportNames());

        self::assertSame(['job1'], $this->storage->retriedJobs);
    }

    public function testUnknownJobThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->retryer->retry('missing');
    }

    public function testJobWithoutPayloadThrows(): void
    {
        $this->storage->jobs['job1'] = ['id' => 'job1', 'queue' => 'async'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no retryable payload/');

        $this->retryer->retry('job1');
    }

    public function testCorruptedPayloadThrows(): void
    {
        $this->storage->jobs['job1'] = [
            'id' => 'job1',
            'queue' => 'async',
            'retry_payload' => base64_encode('not-a-serialized-object'),
        ];

        $this->expectException(\RuntimeException::class);

        $this->retryer->retry('job1');
    }
}
