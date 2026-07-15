<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the self-contained dashboard page (no build step, no CDN assets).
 */
final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $html = file_get_contents(\dirname(__DIR__, 2) . '/templates/dashboard.html');
        if ($html === false) {
            return new Response('Dashboard template missing.', 500);
        }

        $basePath = rtrim($request->getBaseUrl() . $request->getPathInfo(), '/');

        return new Response(
            str_replace('__BASE_PATH__', htmlspecialchars($basePath, \ENT_QUOTES), $html),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }
}
