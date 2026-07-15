<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon;

use Saifulferoz\SymfonyHorizon\Collector\MetricsCollector;
use Saifulferoz\SymfonyHorizon\Command\HorizonCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonContinueCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonPauseCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonTerminateCommand;
use Saifulferoz\SymfonyHorizon\Command\SnapshotCommand;
use Saifulferoz\SymfonyHorizon\Controller\ApiController;
use Saifulferoz\SymfonyHorizon\Controller\DashboardController;
use Saifulferoz\SymfonyHorizon\EventListener\DispatchedAtListener;
use Saifulferoz\SymfonyHorizon\EventListener\WorkerEventSubscriber;
use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Storage\RedisFactory;
use Saifulferoz\SymfonyHorizon\Storage\RedisStorage;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

final class SymfonyHorizonBundle extends AbstractBundle
{
    protected string $extensionAlias = 'symfony_horizon';

    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line ArrayNodeDefinition
        $root = $definition->rootNode();

        $root->children()
            ->arrayNode('redis')->addDefaultsIfNotSet()->children()
                ->scalarNode('dsn')
                    ->defaultValue('redis://localhost:6379')
                    ->info('Redis DSN, e.g. redis://:password@localhost:6379/2')
                ->end()
                ->scalarNode('client_service')
                    ->defaultNull()
                    ->info('Service id of an existing \Redis or Predis\Client instance; takes precedence over dsn')
                ->end()
                ->scalarNode('prefix')->defaultValue('horizon:')->end()
            ->end()->end()

            ->arrayNode('metrics')->addDefaultsIfNotSet()->children()
                ->integerNode('flush_batch')->defaultValue(25)->min(1)
                    ->info('Flush buffered job records to Redis after this many jobs')
                ->end()
                ->integerNode('flush_interval')->defaultValue(3)->min(1)
                    ->info('Flush buffered job records at least every N seconds')
                ->end()
                ->floatNode('sampling')->defaultValue(1.0)->min(0.0)->max(1.0)
                    ->info('Fraction of individual job records to store; aggregate counters always count every job')
                ->end()
                ->booleanNode('capture_payload')->defaultFalse()
                    ->info('Store (truncated) message payloads for completed jobs; failed jobs always keep a payload')
                ->end()
                ->integerNode('payload_max_bytes')->defaultValue(10240)->min(256)->end()
                ->booleanNode('wait_time_stamp')->defaultTrue()
                    ->info('Add a dispatch timestamp stamp to async messages so queue wait time can be measured')
                ->end()
            ->end()->end()

            ->arrayNode('trim')->addDefaultsIfNotSet()->children()
                ->integerNode('recent')->defaultValue(60)->info('Minutes to keep completed job records')->end()
                ->integerNode('failed')->defaultValue(10080)->info('Minutes to keep failed job records (default 7 days)')->end()
                ->integerNode('metrics')->defaultValue(1440)->info('Minutes to keep per-minute metric buckets (default 24h)')->end()
                ->integerNode('snapshots')->defaultValue(10080)->info('Minutes to keep metric snapshots (default 7 days)')->end()
            ->end()->end()

            ->arrayNode('supervisors')
                ->info('Named supervisor blocks started by the horizon command, like Laravel Horizon environments')
                ->useAttributeAsKey('name')
                ->arrayPrototype()->children()
                    ->arrayNode('transports')
                        ->isRequired()->requiresAtLeastOneElement()
                        ->scalarPrototype()->end()
                    ->end()
                    ->integerNode('min_processes')->defaultValue(1)->min(0)->end()
                    ->integerNode('max_processes')->defaultValue(1)->min(1)->end()
                    ->enumNode('balance')->values(['off', 'auto'])->defaultValue('off')
                        ->info('"auto" scales processes between min and max based on queue depth')
                    ->end()
                    ->integerNode('scale_factor')->defaultValue(10)->min(1)
                        ->info('Pending messages one worker is expected to absorb when autoscaling')
                    ->end()
                    ->integerNode('autoscale_cooldown')->defaultValue(3)->min(1)
                        ->info('Seconds between autoscaling decisions')
                    ->end()
                    ->integerNode('memory_limit')->defaultValue(128)->min(16)
                        ->info('MB passed to messenger:consume --memory-limit; the worker restarts above it')
                    ->end()
                    ->integerNode('time_limit')->defaultValue(3600)->min(30)
                        ->info('Seconds passed to messenger:consume --time-limit; the worker recycles after it')
                    ->end()
                    ->arrayNode('consume_options')
                        ->info('Extra CLI options for messenger:consume, e.g. ["--queues=high"]')
                        ->scalarPrototype()->end()
                    ->end()
                ->end()->end()
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // --- Storage ---------------------------------------------------------
        if ($config['redis']['client_service'] !== null) {
            $services->alias('symfony_horizon.redis', $config['redis']['client_service']);
        } else {
            $services->set('symfony_horizon.redis', \stdClass::class)
                ->factory([RedisFactory::class, 'create'])
                ->args([$config['redis']['dsn']]);
        }

        // The client is injected as a service closure: no Redis connection is
        // opened until storage is actually used (a DI lazy proxy is avoided on
        // purpose — it would hide the concrete client class from RedisStorage).
        $services->set('symfony_horizon.storage', RedisStorage::class)
            ->args([
                service_closure('symfony_horizon.redis'),
                $config['redis']['prefix'],
                $config['trim'],
            ]);
        $services->alias(StorageInterface::class, 'symfony_horizon.storage');

        // --- Worker-side metrics collection ----------------------------------
        $services->set('symfony_horizon.collector', MetricsCollector::class)
            ->args([
                service('symfony_horizon.storage'),
                $config['metrics'],
            ]);

        // Worker* events only fire inside messenger:consume processes, so this
        // subscriber is never instantiated during web requests.
        $services->set('symfony_horizon.worker_subscriber', WorkerEventSubscriber::class)
            ->args([service('symfony_horizon.collector')])
            ->tag('kernel.event_subscriber');

        if ($config['metrics']['wait_time_stamp']) {
            $services->set('symfony_horizon.dispatched_at_listener', DispatchedAtListener::class)
                ->tag('kernel.event_subscriber');
        }

        // --- Supervisor -------------------------------------------------------
        $services->set('symfony_horizon.queue_depth_provider', QueueDepthProvider::class)
            ->args([service('messenger.receiver_locator')]);

        $services->set('symfony_horizon.autoscaler', AutoScaler::class);

        $services->set('symfony_horizon.command.horizon', HorizonCommand::class)
            ->args([
                service('symfony_horizon.storage'),
                service('symfony_horizon.queue_depth_provider'),
                service('symfony_horizon.autoscaler'),
                $config['supervisors'],
                param('kernel.project_dir'),
                param('kernel.environment'),
            ])
            ->tag('console.command');

        foreach ([
            'symfony_horizon.command.pause' => HorizonPauseCommand::class,
            'symfony_horizon.command.continue' => HorizonContinueCommand::class,
            'symfony_horizon.command.terminate' => HorizonTerminateCommand::class,
            'symfony_horizon.command.snapshot' => SnapshotCommand::class,
        ] as $id => $class) {
            $services->set($id, $class)
                ->args([service('symfony_horizon.storage')])
                ->tag('console.command');
        }

        // --- Retry ------------------------------------------------------------
        $services->set('symfony_horizon.retryer', FailedJobRetryer::class)
            ->args([
                service('symfony_horizon.storage'),
                service('messenger.default_bus'),
            ]);

        // --- Dashboard / API ---------------------------------------------------
        $services->set(DashboardController::class)
            ->tag('controller.service_arguments');

        $services->set(ApiController::class)
            ->args([
                service('symfony_horizon.storage'),
                service('symfony_horizon.retryer'),
            ])
            ->tag('controller.service_arguments');
    }
}
