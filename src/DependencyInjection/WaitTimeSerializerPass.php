<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\DependencyInjection;

use Saifulferoz\SymfonyHorizon\Transport\WaitTimeSerializer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Decorates the application's Messenger serializer(s) so queue wait time can be
 * measured through a scalar transport header, without the bundle appearing in
 * the wire format.
 *
 * Decoration is used rather than an event listener because headers are only
 * reachable at the serializer boundary, and it transparently wraps whichever
 * serializer the app configured (default, JSON, or a custom one).
 */
final class WaitTimeSerializerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('symfony_horizon.wait_time')) {
            return;
        }

        $enabled = (bool) $container->getParameter('symfony_horizon.wait_time');
        $container->getParameterBag()->remove('symfony_horizon.wait_time');

        if (!$enabled) {
            return;
        }

        foreach ($this->serializerIds($container) as $id) {
            $decorator = new Definition(WaitTimeSerializer::class);
            $decorator->setDecoratedService($id);
            $decorator->setArguments([new Reference('.inner')]);
            $container->setDefinition('symfony_horizon.serializer.' . md5($id), $decorator);
        }
    }

    /**
     * Every serializer a transport could be using.
     *
     * @return list<string>
     */
    private function serializerIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach (['messenger.default_serializer', 'messenger.transport.native_php_serializer'] as $id) {
            if ($container->has($id) && !$container->hasDefinition('symfony_horizon.serializer.' . md5($id))) {
                $ids[] = $id;
            }
        }

        // Transport-specific serializers configured per transport.
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'messenger.transport.') && str_ends_with($id, '.serializer')) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
