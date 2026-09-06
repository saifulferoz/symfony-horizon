<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the bundle work with zero configuration.
 *
 * When no supervisors are configured, this discovers the application's
 * consumable Messenger transports and builds a single "default" supervisor
 * block for them, so `bin/console horizon` works immediately after install.
 *
 * Runs after MessengerPass, which is what populates the consumable receiver
 * list on the messenger:consume command definition.
 */
final class AutoDiscoverTransportsPass implements CompilerPassInterface
{
    /**
     * Transports that must never be consumed by a worker: `sync://` is handled
     * in-process, and the failure transport is drained deliberately via
     * messenger:failed:retry, not by a always-on worker.
     */
    private const NEVER_CONSUME = ['sync'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('symfony_horizon.autodiscover')) {
            return;
        }

        $autodiscover = (bool) $container->getParameter('symfony_horizon.autodiscover');
        $container->getParameterBag()->remove('symfony_horizon.autodiscover');

        if (!$autodiscover || !$container->hasDefinition('symfony_horizon.command.horizon')) {
            return;
        }

        $transports = $this->discoverTransports($container);
        if ($transports === []) {
            return;
        }

        $defaults = $container->hasParameter('symfony_horizon.supervisor_defaults')
            ? (array) $container->getParameter('symfony_horizon.supervisor_defaults')
            : [];
        $container->getParameterBag()->remove('symfony_horizon.supervisor_defaults');

        $block = ['default' => ['transports' => $transports] + $defaults];

        $container->getDefinition('symfony_horizon.command.horizon')->replaceArgument(3, $block);

        // horizon:status reports on the same set, so it must see it too.
        if ($container->hasDefinition('symfony_horizon.command.status')) {
            $container->getDefinition('symfony_horizon.command.status')->replaceArgument(2, $block);
        }
    }

    /**
     * @return list<string>
     */
    private function discoverTransports(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition('console.command.messenger_consume_messages')) {
            return [];
        }

        // MessengerPass puts the consumable receiver names in argument 4.
        $names = $container->getDefinition('console.command.messenger_consume_messages')->getArgument(4);
        if (!\is_array($names)) {
            return [];
        }

        $failureTransports = $this->failureTransportNames($container);

        $transports = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if (\in_array($name, self::NEVER_CONSUME, true) || \in_array($name, $failureTransports, true)) {
                continue;
            }
            $transports[] = $name;
        }

        return array_values(array_unique($transports));
    }

    /**
     * Failure transports hold messages that already failed; they are drained
     * deliberately with messenger:failed:retry, never by an always-on worker.
     *
     * @return list<string>
     */
    private function failureTransportNames(ContainerBuilder $container): array
    {
        $names = [];

        // FrameworkBundle aliases the global failure transport to the service
        // id "messenger.transport.<name>", so strip the prefix to get the name.
        if ($container->hasAlias('messenger.failure_transports.default')) {
            $target = (string) $container->getAlias('messenger.failure_transports.default');
            if (str_starts_with($target, 'messenger.transport.')) {
                $names[] = substr($target, \strlen('messenger.transport.'));
            }
        }

        // Per-transport failure transports are registered in this locator.
        if ($container->hasDefinition('messenger.failure_transports')) {
            $argument = $container->getDefinition('messenger.failure_transports')->getArgument(0);
            if (\is_array($argument)) {
                foreach (array_keys($argument) as $name) {
                    if (\is_string($name) && $name !== '') {
                        $names[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }
}
