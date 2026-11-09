<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Controller\DashboardController;
use Symfony\Component\HttpFoundation\Request;

final class DashboardControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{transport: string, interval: int, mercure: array{topic_prefix: string, public_url: ?string}}
     */
    private function realtime(array $overrides = []): array
    {
        return $overrides + [
            'transport' => 'polling',
            'interval' => 3,
            'mercure' => ['topic_prefix' => 'horizon://dashboard/', 'public_url' => null],
        ];
    }

    private function render(DashboardController $controller): string
    {
        return (string) $controller(Request::create('http://localhost/horizon/'))->getContent();
    }

    public function testInjectsParsableJsonNotHtmlEntities(): void
    {
        // The config lands inside a JS object literal. HTML-escaping it would
        // emit &quot; and break the dashboard at parse time.
        $html = $this->render(new DashboardController($this->realtime()));

        self::assertStringContainsString('{"transport":"polling"', $html);
        self::assertStringNotContainsString('&quot;transport&quot;', $html);
    }

    public function testFallsBackToPollingWhenNoAuthorizationServiceExists(): void
    {
        // Mercure requested but unusable (no Authorization service, or a hub on
        // another domain): the page must still render and poll rather than
        // offer a stream that can only 401.
        $html = $this->render(new DashboardController(
            $this->realtime(['transport' => 'mercure']),
            null,
        ));

        self::assertStringContainsString('"transport":"polling"', $html);
        self::assertStringNotContainsString('"transport":"mercure"', $html);
    }

    public function testAdvertisesTheConfiguredTopics(): void
    {
        $html = $this->render(new DashboardController($this->realtime()));

        self::assertStringContainsString('horizon://dashboard/stats', $html);
        self::assertStringContainsString('horizon://dashboard/workers', $html);
    }

    public function testLeavesNoUnsubstitutedPlaceholders(): void
    {
        $html = $this->render(new DashboardController($this->realtime()));

        self::assertStringNotContainsString('__REALTIME__', $html);
        self::assertStringNotContainsString('__BASE_PATH__', $html);
    }
}
