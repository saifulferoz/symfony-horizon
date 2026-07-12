<?php

namespace Saifulferoz\SymfonyHorizon\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class DashboardController
{
    private ?AuthorizationCheckerInterface $authorizationChecker;
    private ?string $role;

    public function __construct(
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?string $role = null
    ) {
        $this->authorizationChecker = $authorizationChecker;
        $this->role = $role;
    }

    #[Route('/horizon', name: 'symfony_horizon_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted();

        $htmlPath = dirname(__DIR__) . '/Resources/views/dashboard.html';
        $html = file_exists($htmlPath) ? file_get_contents($htmlPath) : '<h1>Symfony Horizon Dashboard</h1>';

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    private function denyAccessUnlessGranted(): void
    {
        if (!$this->authorizationChecker || !$this->role) {
            return;
        }

        if (!$this->authorizationChecker->isGranted($this->role)) {
            throw new AccessDeniedException(sprintf('Access Denied. Required role: %s', $this->role));
        }
    }
}
