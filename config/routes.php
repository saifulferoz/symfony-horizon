<?php

declare(strict_types=1);

use Saifulferoz\SymfonyHorizon\Controller\ApiController;
use Saifulferoz\SymfonyHorizon\Controller\DashboardController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import from the host application, e.g. config/routes/symfony_horizon.yaml:
 *
 *   symfony_horizon:
 *       resource: '@SymfonyHorizonBundle/config/routes.php'
 *       prefix: /horizon
 *
 * Protect the prefix with your own security config (access_control / voters).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('symfony_horizon.dashboard', '/')
        ->controller(DashboardController::class)
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.stats', '/api/stats')
        ->controller([ApiController::class, 'stats'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.workers', '/api/workers')
        ->controller([ApiController::class, 'workers'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.supervisors', '/api/supervisors')
        ->controller([ApiController::class, 'supervisors'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.jobs_recent', '/api/jobs/recent')
        ->controller([ApiController::class, 'recentJobs'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.jobs_failed', '/api/jobs/failed')
        ->controller([ApiController::class, 'failedJobs'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.job', '/api/jobs/{id}')
        ->controller([ApiController::class, 'job'])
        ->requirements(['id' => '[A-Za-z0-9\-_.:@]+'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.job_retry', '/api/jobs/{id}/retry')
        ->controller([ApiController::class, 'retryJob'])
        ->requirements(['id' => '[A-Za-z0-9\-_.:@]+'])
        ->methods(['POST']);

    $routes->add('symfony_horizon.api.job_delete', '/api/jobs/{id}')
        ->controller([ApiController::class, 'deleteJob'])
        ->requirements(['id' => '[A-Za-z0-9\-_.:@]+'])
        ->methods(['DELETE']);

    $routes->add('symfony_horizon.api.metrics_queues', '/api/metrics/queues')
        ->controller([ApiController::class, 'queueMetrics'])
        ->methods(['GET']);

    $routes->add('symfony_horizon.api.metrics_classes', '/api/metrics/classes')
        ->controller([ApiController::class, 'classMetrics'])
        ->methods(['GET']);
};
