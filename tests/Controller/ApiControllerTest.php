<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Controller\ApiController;
use Saifulferoz\SymfonyHorizon\Realtime\SnapshotBuilder;
use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApiControllerTest extends TestCase
{
    private SpyStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new SpyStorage();
    }

    private function controller(bool $readOnly = false): ApiController
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps);
            }
        };

        return new ApiController(
            $this->storage,
            new FailedJobRetryer($this->storage, $bus),
            new SnapshotBuilder($this->storage),
            $readOnly,
        );
    }

    /** @return array<string, mixed> */
    private function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function testJobDetailReturns404WhenMissing(): void
    {
        $response = $this->controller()->job('nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Job not found', $this->json($response)['error']);
    }

    public function testJobDetailNormalisesStoredStrings(): void
    {
        $this->storage->jobs['j1'] = [
            'id' => 'j1',
            'class' => 'App\\Message\\Demo',
            'queue' => 'async',
            'status' => 'failed',
            'duration_ms' => '12.5',
            'peak_memory' => '4194304',
            'attempts' => '3',
            'tags' => '["billing"]',
            'retry_payload' => 'x',
        ];

        $job = $this->json($this->controller()->job('j1'))['job'];

        self::assertSame(12.5, $job['duration_ms'], 'numeric fields are typed for the UI');
        self::assertSame(4194304, $job['peak_memory']);
        self::assertSame(3, $job['attempts']);
        self::assertSame(['billing'], $job['tags']);
        self::assertTrue($job['retryable']);
    }

    public function testJobDetailNeverLeaksTheRetryPayload(): void
    {
        // The payload is a serialized message; the UI only needs to know a
        // retry is possible, not the bytes it would deserialize.
        $this->storage->jobs['j1'] = ['id' => 'j1', 'retry_payload' => base64_encode('SECRET-BLOB')];

        $body = (string) $this->controller()->job('j1')->getContent();

        self::assertStringNotContainsString('SECRET-BLOB', $body);
        self::assertStringNotContainsString('retry_payload', $body);
    }

    public function testRetryAndDeleteAreRefusedInReadOnlyMode(): void
    {
        $this->storage->jobs['j1'] = ['id' => 'j1', 'class' => 'X', 'retry_payload' => 'x'];
        $controller = $this->controller(readOnly: true);

        self::assertSame(403, $controller->retryJob('j1')->getStatusCode());
        self::assertSame(403, $controller->deleteJob('j1')->getStatusCode());
        self::assertSame([], $this->storage->retriedJobs, 'nothing may be dispatched in read-only mode');
        self::assertArrayHasKey('j1', $this->storage->jobs, 'nothing may be deleted in read-only mode');
    }

    public function testRetryReportsAFailureAsABadRequest(): void
    {
        $response = $this->controller()->retryJob('unknown');

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->json($response));
    }

    public function testPaginationIsClamped(): void
    {
        $controller = $this->controller();

        // Absurd input must not become an unbounded Redis read.
        $controller->recentJobs(new Request(['limit' => '100000', 'offset' => '-5']));

        self::assertSame([100, 0], $this->storage->lastPage, 'limit is capped and offset floored');
    }

    public function testStatsShapeIsStable(): void
    {
        $stats = $this->json($this->controller()->stats());

        foreach (['jobs_total', 'failed_total', 'failed_count', 'jobs_per_minute', 'avg_duration_ms', 'avg_memory', 'workers', 'supervisors', 'series'] as $key) {
            self::assertArrayHasKey($key, $stats, \sprintf('the dashboard depends on "%s"', $key));
        }
    }
}
