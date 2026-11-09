<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Controller;

use Saifulferoz\SymfonyHorizon\Realtime\DashboardBroadcaster;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;

/**
 * Serves the self-contained dashboard page (no build step, no CDN assets).
 *
 * In mercure mode it also mints the subscriber cookie. That happens here, in a
 * controller sitting behind the host application's firewall, so the hub only
 * ever streams to viewers the application itself has already authorised.
 */
final class DashboardController
{
    /**
     * @param array{transport: string, interval: int, mercure: array{topic_prefix: string, public_url: ?string}} $realtime
     */
    public function __construct(
        private readonly array $realtime = ['transport' => 'polling', 'interval' => 3, 'mercure' => ['topic_prefix' => 'horizon://dashboard/', 'public_url' => null]],
        private readonly ?Authorization $authorization = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $html = file_get_contents(\dirname(__DIR__, 2) . '/templates/dashboard.html');
        if ($html === false) {
            return new Response('Dashboard template missing.', 500);
        }

        $basePath = rtrim($request->getBaseUrl() . $request->getPathInfo(), '/');
        $prefix = $this->realtime['mercure']['topic_prefix'];
        $topics = [
            $prefix . DashboardBroadcaster::TOPIC_STATS,
            $prefix . DashboardBroadcaster::TOPIC_WORKERS,
        ];

        $config = [
            'transport' => $this->realtime['transport'],
            'interval' => $this->realtime['interval'],
            'topics' => $topics,
            'hub' => null,
        ];

        $response = new Response(
            '',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );

        if ($config['transport'] === 'mercure') {
            $config['hub'] = $this->realtime['mercure']['public_url'];

            try {
                if ($this->authorization === null) {
                    throw new \LogicException('No Mercure authorization service available.');
                }

                // Scoped to the horizon topics only — never "*" — so the cookie
                // cannot be replayed to read another application's streams.
                $response->headers->setCookie($this->authorization->createCookie($request, $topics));
            } catch (\Throwable) {
                // Without a usable cookie the stream would 401 on every attempt;
                // fall back to polling rather than show a dead dashboard.
                $config['transport'] = 'polling';
            }
        }

        // __REALTIME__ sits inside a JS string literal, so it is JSON-escaped
        // (JSON_HEX_* covers <, >, ', " and &) rather than HTML-escaped: an
        // HTML entity here would break JSON.parse in the browser.
        $json = json_encode(
            $config,
            \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP,
        ) ?: '{}';

        $response->setContent(strtr($html, [
            '__BASE_PATH__' => htmlspecialchars($basePath, \ENT_QUOTES),
            '__REALTIME__' => $json,
        ]));

        return $response;
    }
}
