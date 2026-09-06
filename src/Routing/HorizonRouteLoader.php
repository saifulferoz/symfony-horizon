<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers the dashboard and API routes automatically, so installing the
 * bundle needs no route-file import. Mount point comes from
 * symfony_horizon.dashboard.path; set dashboard.enabled to false to opt out
 * and wire the routes yourself.
 */
final class HorizonRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(private readonly string $prefix)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException('The Symfony Horizon route loader is already loaded.');
        }
        $this->loaded = true;

        /** @var RouteCollection $routes */
        $routes = $this->import(\dirname(__DIR__, 2) . '/config/routes.php', 'php');
        $routes->addPrefix($this->prefix);

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'symfony_horizon';
    }
}
