<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon;

use Saifulferoz\SymfonyHorizon\Collector\MetricsCollector;
use Saifulferoz\SymfonyHorizon\Command\HorizonBroadcastCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonContinueCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonPauseCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonStatusCommand;
use Saifulferoz\SymfonyHorizon\Command\HorizonTerminateCommand;
use Saifulferoz\SymfonyHorizon\Command\SnapshotCommand;
use Saifulferoz\SymfonyHorizon\Controller\ApiController;
use Saifulferoz\SymfonyHorizon\Controller\DashboardController;
use Saifulferoz\SymfonyHorizon\DependencyInjection\AutoDiscoverTransportsPass;
use Saifulferoz\SymfonyHorizon\DependencyInjection\WaitTimeSerializerPass;
use Saifulferoz\SymfonyHorizon\EventListener\WorkerEventSubscriber;
use Saifulferoz\SymfonyHorizon\Realtime\DashboardBroadcaster;
use Saifulferoz\SymfonyHorizon\Realtime\MercureUpdatePublisher;
use Saifulferoz\SymfonyHorizon\Realtime\NullUpdatePublisher;
use Saifulferoz\SymfonyHorizon\Realtime\SnapshotBuilder;
use Saifulferoz\SymfonyHorizon\Retry\FailedJobRetryer;
use Saifulferoz\SymfonyHorizon\Routing\HorizonRouteLoader;
use Saifulferoz\SymfonyHorizon\Storage\RedisFactory;
use Saifulferoz\SymfonyHorizon\Storage\RedisStorage;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SymfonyHorizonBundle extends AbstractBundle
{
    protected string $extensionAlias = 'symfony_horizon';

    /**
     * Defaults applied to an auto-discovered supervisor block. Kept in sync with
     * the config tree below.
     *
     * @return array<string, mixed>
     */
    private static function supervisorDefaults(): array
    {
        return [
            'min_processes' => 1,
            'max_processes' => 3,
            'balance' => 'auto',
            'scale_factor' => 10,
            'autoscale_cooldown' => 3,
            'memory_limit' => 128,
            'time_limit' => 3600,
            'consume_options' => [],
        ];
    }

    public function configure(DefinitionConfigurator $definition): void
    {
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
                ->booleanNode('wait_time')->defaultTrue()
                    ->info('Measure how long messages wait in the queue, via an "X-Horizon-Dispatched-At" transport header')
                ->end()
            ->end()->end()

            ->arrayNode('trim')->addDefaultsIfNotSet()->children()
                ->integerNode('recent')->defaultValue(60)->info('Minutes to keep completed job records')->end()
                ->integerNode('failed')->defaultValue(10080)->info('Minutes to keep failed job records (default 7 days)')->end()
                ->integerNode('metrics')->defaultValue(1440)->info('Minutes to keep per-minute metric buckets (default 24h)')->end()
                ->integerNode('snapshots')->defaultValue(10080)->info('Minutes to keep metric snapshots (default 7 days)')->end()
            ->end()->end()

            ->arrayNode('dashboard')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultTrue()
                    ->info('Register the dashboard and API routes automatically')
                ->end()
                ->scalarNode('path')->defaultValue('/horizon')
                    ->info('URL prefix the dashboard is mounted at; protect it in security.yaml')
                ->end()
                ->booleanNode('read_only')->defaultFalse()
                    ->info('Disable retry and delete: the dashboard becomes purely observational')
                ->end()
                ->arrayNode('realtime')->addDefaultsIfNotSet()->children()
                    ->enumNode('transport')->values(['polling', 'mercure'])->defaultValue('polling')
                        ->info('"polling" fetches on an interval; "mercure" pushes over SSE and falls back to polling if the stream drops')
                    ->end()
                    ->integerNode('interval')->defaultValue(3)->min(1)
                        ->info('Seconds between dashboard polls (also the fallback cadence in mercure mode)')
                    ->end()
                    ->arrayNode('mercure')->addDefaultsIfNotSet()->children()
                        ->scalarNode('hub')->defaultNull()
                            ->info('Hub service id; defaults to mercure.hub.default')
                        ->end()
                        ->scalarNode('topic_prefix')->defaultValue('horizon://dashboard/')->end()
                        ->integerNode('publish_interval')->defaultValue(1)->min(1)
                            ->info('Seconds between pushes from the supervisor')
                        ->end()
                    ->end()->end()
                ->end()->end()
            ->end()->end()

            ->scalarNode('console_path')->defaultNull()
                ->info('Path to the console entrypoint, absolute or relative to the project dir (default: bin/console)')
            ->end()

            ->scalarNode('php_binary')->defaultNull()
                ->info('PHP binary used to spawn workers. Auto-detected; set it for FrankenPHP ("frankenphp php-cli") or a custom interpreter.')
            ->end()

            ->booleanNode('autodiscover')->defaultTrue()
                ->info('When no supervisors are configured, build a "default" one from the app\'s consumable Messenger transports')
            ->end()

            ->arrayNode('supervisors')
                ->info('Named supervisor blocks started by the horizon command, like Laravel Horizon environments. Omit entirely to auto-discover transports.')
                ->useAttributeAsKey('name')
                ->arrayPrototype()->children()
                    ->arrayNode('transports')
                        ->info('Transport names; defaults to every consumable transport when omitted')
                        ->requiresAtLeastOneElement()
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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // After MessengerPass, which is what fills in the consumable receiver list.
        $container->addCompilerPass(new AutoDiscoverTransportsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
        $container->addCompilerPass(new WaitTimeSerializerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Mount the dashboard without the user having to import a route file.
        $config = $builder->getExtensionConfig($this->extensionAlias);
        $dashboard = array_merge(...array_column($config, 'dashboard') ?: [[]]);

        if (($dashboard['enabled'] ?? true) === false) {
            return;
        }

        $container->extension('framework', [
            'router' => ['resource' => 'symfony_horizon_routes', 'type' => 'symfony_horizon'],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Zero-config path: with no supervisors defined, a compiler pass fills
        // in a "default" block from the app's own transports once they are known.
        $builder->setParameter('symfony_horizon.autodiscover', $config['autodiscover'] && $config['supervisors'] === []);
        $builder->setParameter('symfony_horizon.supervisor_defaults', self::supervisorDefaults());

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
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'horizon']);

        // Worker* events only fire inside messenger:consume processes, so this
        // subscriber is never instantiated during web requests.
        $services->set('symfony_horizon.worker_subscriber', WorkerEventSubscriber::class)
            ->args([service('symfony_horizon.collector')])
            ->tag('kernel.event_subscriber');

        // Queue wait time travels as a scalar transport header, applied by
        // decorating the app's serializer(s) in a compiler pass.
        $builder->setParameter('symfony_horizon.wait_time', $config['metrics']['wait_time']);

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
                $config['php_binary'],
                $config['console_path'],
                service('symfony_horizon.broadcaster'),
            ])
            ->tag('console.command');

        $services->set('symfony_horizon.command.broadcast', HorizonBroadcastCommand::class)
            ->args([service('symfony_horizon.broadcaster')])
            ->tag('console.command');

        $services->set('symfony_horizon.command.status', HorizonStatusCommand::class)
            ->args([
                service('symfony_horizon.storage'),
                service('symfony_horizon.queue_depth_provider'),
                $config['supervisors'],
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
        // Shared by the JSON API and the real-time broadcaster so both
        // transports emit identical payloads.
        $services->set('symfony_horizon.snapshots', SnapshotBuilder::class)
            ->args([service('symfony_horizon.storage')]);

        // --- Real-time -----------------------------------------------------
        $realtime = $config['dashboard']['realtime'];

        if ($realtime['transport'] === 'mercure') {
            $hub = $realtime['mercure']['hub'] ?? 'mercure.hub.default';

            // Fail at container build with an actionable message: a dashboard
            // that silently never updates is far harder to diagnose.
            if (!interface_exists(\Symfony\Component\Mercure\HubInterface::class)) {
                throw new \LogicException('symfony_horizon.dashboard.realtime.transport is "mercure" but the Mercure component is missing. Run: composer require symfony/mercure-bundle');
            }

            $services->set('symfony_horizon.publisher', MercureUpdatePublisher::class)
                ->args([service($hub), service('logger')->nullOnInvalid()])
                ->tag('monolog.logger', ['channel' => 'horizon']);
        } else {
            $services->set('symfony_horizon.publisher', NullUpdatePublisher::class);
        }

        $services->set('symfony_horizon.broadcaster', DashboardBroadcaster::class)
            ->args([
                service('symfony_horizon.snapshots'),
                service('symfony_horizon.publisher'),
                service('symfony_horizon.storage'),
                $realtime['mercure']['topic_prefix'],
                $realtime['mercure']['publish_interval'],
            ]);

        if ($config['dashboard']['enabled']) {
            $services->set('symfony_horizon.route_loader', HorizonRouteLoader::class)
                ->args([rtrim($config['dashboard']['path'], '/')])
                ->tag('routing.loader');
        }

        $services->set(DashboardController::class)
            ->args([
                [
                    'transport' => $realtime['transport'],
                    'interval' => $realtime['interval'],
                    'mercure' => [
                        'topic_prefix' => $realtime['mercure']['topic_prefix'],
                        'public_url' => '%env(default::MERCURE_PUBLIC_URL)%',
                    ],
                ],
                service(\Symfony\Component\Mercure\Authorization::class)->nullOnInvalid(),
            ])
            ->tag('controller.service_arguments');

        $services->set(ApiController::class)
            ->args([
                service('symfony_horizon.storage'),
                service('symfony_horizon.retryer'),
                service('symfony_horizon.snapshots'),
                $config['dashboard']['read_only'],
            ])
            ->tag('controller.service_arguments');
    }
}
