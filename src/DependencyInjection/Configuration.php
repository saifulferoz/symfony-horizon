<?php

namespace Saifulferoz\SymfonyHorizon\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('symfony_horizon');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('prefix')
                    ->defaultValue('horizon:')
                    ->info('The prefix used for all Redis keys.')
                ->end()
                ->scalarNode('failure_transport')
                    ->defaultValue('failed')
                    ->info('The receiver name for the failure transport.')
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->values(['redis', 'doctrine', 'custom'])
                            ->defaultValue('redis')
                        ->end()
                        ->scalarNode('redis_connection')
                            ->defaultValue('snc_redis.default')
                            ->info('The Redis service ID or connection name.')
                        ->end()
                        ->scalarNode('custom_service')
                            ->defaultNull()
                            ->info('If type is custom, specify the service ID.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dashboard')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('/horizon')
                        ->end()
                        ->scalarNode('role')
                            ->defaultValue('ROLE_ADMIN')
                            ->info('Default security role to access the dashboard.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('supervisors')
                    ->useAttributeAsKey('name')
                    ->defaultValue([
                        'default' => [
                            'connection' => 'async',
                            'queues' => ['async'],
                            'processes' => 3,
                            'max_processes' => 10,
                            'balance' => 'simple',
                            'memory_limit' => 128,
                            'time_limit' => 3600,
                            'sleep' => 3,
                        ]
                    ])
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('connection')
                                ->isRequired()
                                ->info('Symfony Messenger transport name.')
                            ->end()
                            ->arrayNode('queues')
                                ->scalarPrototype()->end()
                                ->info('Queues/transports to consume.')
                            ->end()
                            ->integerNode('processes')
                                ->defaultValue(3)
                                ->min(1)
                            ->end()
                            ->integerNode('max_processes')
                                ->defaultValue(10)
                                ->min(1)
                            ->end()
                            ->enumNode('balance')
                                ->values(['simple', 'auto', 'false'])
                                ->defaultValue('simple')
                            ->end()
                            ->integerNode('memory_limit')
                                ->defaultValue(128)
                                ->info('Memory limit in MB before worker process exits.')
                            ->end()
                            ->integerNode('time_limit')
                                ->defaultValue(3600)
                                ->info('Time limit in seconds before worker process exits.')
                            ->end()
                            ->integerNode('sleep')
                                ->defaultValue(3)
                                ->info('Seconds to sleep when no messages are found.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
