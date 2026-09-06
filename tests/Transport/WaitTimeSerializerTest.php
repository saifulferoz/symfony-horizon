<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Transport\DispatchedAt;
use Saifulferoz\SymfonyHorizon\Transport\WaitTimeSerializer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Queue wait time must never make the bundle part of the wire format: a
 * consumer without this bundle installed has to be able to decode the message.
 */
final class WaitTimeSerializerTest extends TestCase
{
    private function serializer(): WaitTimeSerializer
    {
        return new WaitTimeSerializer(new PhpSerializer());
    }

    public function testEncodeAddsOnlyAScalarHeader(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(new \stdClass()));

        self::assertArrayHasKey(WaitTimeSerializer::HEADER, $encoded['headers']);
        self::assertIsString($encoded['headers'][WaitTimeSerializer::HEADER]);
        self::assertGreaterThan(0.0, (float) $encoded['headers'][WaitTimeSerializer::HEADER]);
    }

    public function testTheBundleNeverAppearsInTheEncodedPayload(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(new \stdClass()));

        $wire = $encoded['body'] . json_encode($encoded['headers']);
        self::assertStringNotContainsString('Saifulferoz', $wire, 'the wire format must not reference this bundle');
        self::assertStringNotContainsString('X-Message-Stamp', $wire, 'no stamp class may be serialized');
    }

    public function testDecodeExposesTheDispatchTimeInProcess(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope(new \stdClass()));

        $stamp = $serializer->decode($encoded)->last(DispatchedAt::class);

        self::assertInstanceOf(DispatchedAt::class, $stamp);
        self::assertSame((float) $encoded['headers'][WaitTimeSerializer::HEADER], $stamp->dispatchedAt);
    }

    public function testTheInProcessStampCanNeverBeSentToATransport(): void
    {
        self::assertInstanceOf(NonSendableStampInterface::class, new DispatchedAt(1.0));
    }

    public function testDecodeToleratesAMissingOrInvalidHeader(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope(new \stdClass()));

        foreach ([[], [WaitTimeSerializer::HEADER => 'not-a-number'], [WaitTimeSerializer::HEADER => '0']] as $headers) {
            $envelope = $serializer->decode(['body' => $encoded['body'], 'headers' => $headers]);
            self::assertNull($envelope->last(DispatchedAt::class), 'an unusable header must be ignored, not fatal');
        }
    }

    public function testDecodePreservesTheInnerSerializersEnvelope(): void
    {
        $serializer = $this->serializer();
        $message = new \stdClass();
        $message->id = 42;

        $decoded = $serializer->decode($serializer->encode(new Envelope($message)));

        self::assertSame(42, $decoded->getMessage()->id);
    }
}
