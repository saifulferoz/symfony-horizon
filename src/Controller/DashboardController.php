<?php

namespace Saifulferoz\SymfonyHorizon\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController
{
    #[Route('/horizon', name: 'symfony_horizon_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $htmlPath = dirname(__DIR__) . '/Resources/views/dashboard.html';
        $html = file_exists($htmlPath) ? file_get_contents($htmlPath) : '<h1>Symfony Horizon Dashboard</h1>';

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
