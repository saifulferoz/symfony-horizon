<?php

namespace Saifulferoz\SymfonyHorizon\DependencyInjection;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Storage\RedisStorage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SymfonyHorizonExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('symfony_horizon.prefix', $config['prefix']);
        $container->setParameter('symfony_horizon.storage_type', $config['storage']['type']);
        $container->setParameter('symfony_horizon.redis_connection_id', $config['storage']['redis_connection']);
        $container->setParameter('symfony_horizon.dashboard_path', $config['dashboard']['path']);
        $container->setParameter('symfony_horizon.dashboard_role', $config['dashboard']['role']);
        $container->setParameter('symfony_horizon.config', $config);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Configure RedisStorage dynamically with connection reference
        if ($container->hasDefinition(RedisStorage::class)) {
            $container->getDefinition(RedisStorage::class)
                ->setArguments([
                    new \Symfony\Component\DependencyInjection\Reference($config['storage']['redis_connection']),
                    '%symfony_horizon.prefix%'
                ]);
        }

        // Alias storage interface to appropriate class
        if ($config['storage']['type'] === 'redis') {
            $container->setAlias(StorageInterface::class, RedisStorage::class)->setPublic(true);
        } elseif ($config['storage']['type'] === 'custom' && $config['storage']['custom_service']) {
            $container->setAlias(StorageInterface::class, $config['storage']['custom_service'])->setPublic(true);
        } else {
            $container->setAlias(StorageInterface::class, RedisStorage::class)->setPublic(true);
        }
    }

    public function getAlias(): string
    {
        return 'symfony_horizon';
    }
}
