<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\DependencyInjection\AutoDiscoverTransportsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Zero-config install: the bundle must build a working supervisor from the
 * application's own Messenger transports.
 */
final class AutoDiscoverTransportsPassTest extends TestCase
{
    /**
     * @param list<string> $consumable
     */
    private function container(array $consumable, bool $autodiscover = true, ?string $failureTransport = 'failed'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('symfony_horizon.autodiscover', $autodiscover);
        $container->setParameter('symfony_horizon.supervisor_defaults', ['balance' => 'auto', 'max_processes' => 3]);

        // Mirrors MessengerPass: consumable receiver names live in argument 4.
        $consume = new Definition('stdClass');
        $consume->setArguments([null, null, null, null, $consumable, null, null]);
        $container->setDefinition('console.command.messenger_consume_messages', $consume);

        if ($failureTransport !== null) {
            $container->setAlias('messenger.failure_transports.default', 'messenger.transport.' . $failureTransport);
        }

        $horizon = new Definition('stdClass');
        $horizon->setArguments([null, null, null, [], null, null]);
        $container->setDefinition('symfony_horizon.command.horizon', $horizon);

        $status = new Definition('stdClass');
        $status->setArguments([null, null, []]);
        $container->setDefinition('symfony_horizon.command.status', $status);

        return $container;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discovered(ContainerBuilder $container): array
    {
        return $container->getDefinition('symfony_horizon.command.horizon')->getArgument(3);
    }

    public function testBuildsADefaultSupervisorFromConsumableTransports(): void
    {
        $container = $this->container(['async', 'high_priority']);

        (new AutoDiscoverTransportsPass())->process($container);

        $config = $this->discovered($container);
        self::assertSame(['default'], array_keys($config));
        self::assertSame(['async', 'high_priority'], $config['default']['transports']);
        self::assertSame('auto', $config['default']['balance'], 'defaults are applied');
    }

    public function testExcludesSyncAndFailureTransports(): void
    {
        $container = $this->container(['async', 'sync', 'failed']);

        (new AutoDiscoverTransportsPass())->process($container);

        self::assertSame(['async'], $this->discovered($container)['default']['transports']);
    }

    public function testStatusCommandSeesTheSameSupervisors(): void
    {
        $container = $this->container(['async']);

        (new AutoDiscoverTransportsPass())->process($container);

        self::assertSame(
            $this->discovered($container),
            $container->getDefinition('symfony_horizon.command.status')->getArgument(2),
        );
    }

    public function testExplicitConfigurationIsNeverOverwritten(): void
    {
        // autodiscover=false is what the extension sets once the user has
        // configured supervisors themselves.
        $container = $this->container(['async'], autodiscover: false);

        (new AutoDiscoverTransportsPass())->process($container);

        self::assertSame([], $this->discovered($container), 'user configuration must win');
    }

    public function testNoTransportsLeavesConfigurationUntouched(): void
    {
        $container = $this->container(['sync']);

        (new AutoDiscoverTransportsPass())->process($container);

        self::assertSame([], $this->discovered($container));
    }

    public function testWorksWithoutAFailureTransport(): void
    {
        $container = $this->container(['async'], failureTransport: null);

        (new AutoDiscoverTransportsPass())->process($container);

        self::assertSame(['async'], $this->discovered($container)['default']['transports']);
    }
}
