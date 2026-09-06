<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Command;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Command\HorizonCommand;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\BrokenStorage;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Control commands are broadcast state, so EVERY running instance must apply
 * them. These tests drive applyPendingCommands() directly, standing in for N
 * horizon processes sharing one storage.
 */
final class HorizonCommandControlTest extends TestCase
{
    private function instance(StorageInterface $storage): HorizonCommand
    {
        $locator = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): object
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new HorizonCommand(
            $storage,
            new QueueDepthProvider($locator),
            new AutoScaler(),
            ['default' => ['transports' => ['async']]],
            '/app',
            'prod',
        );
    }

    /**
     * @return list<string> commands each instance applied
     */
    private function apply(HorizonCommand $command, object $supervisor): array
    {
        $method = new \ReflectionMethod($command, 'applyPendingCommands');
        $method->invoke($command, [$supervisor], new NullOutput());

        return $supervisor->applied;
    }

    private function supervisorSpy(): object
    {
        return new class {
            /** @var list<string> */
            public array $applied = [];

            public function pause(): void
            {
                $this->applied[] = 'pause';
            }

            public function resume(): void
            {
                $this->applied[] = 'continue';
            }
        };
    }

    public function testEveryInstanceAppliesTheSameBroadcastCommand(): void
    {
        $storage = new SpyStorage();

        // Three hosts running horizon against one storage.
        $instances = [$this->instance($storage), $this->instance($storage), $this->instance($storage)];
        $spies = [$this->supervisorSpy(), $this->supervisorSpy(), $this->supervisorSpy()];

        // Each adopts the current (empty) state on its first tick.
        foreach ($instances as $i => $instance) {
            self::assertSame([], $this->apply($instance, $spies[$i]));
        }

        $storage->pushCommand(StorageInterface::CMD_PAUSE);

        foreach ($instances as $i => $instance) {
            self::assertSame(['pause'], $this->apply($instance, $spies[$i]), 'instance ' . $i . ' must observe the pause');
        }
    }

    public function testACommandIsAppliedOnlyOncePerInstance(): void
    {
        $storage = new SpyStorage();
        $instance = $this->instance($storage);
        $spy = $this->supervisorSpy();

        $this->apply($instance, $spy);
        $storage->pushCommand(StorageInterface::CMD_PAUSE);

        $this->apply($instance, $spy);
        $this->apply($instance, $spy);
        $this->apply($instance, $spy);

        self::assertSame(['pause'], $spy->applied, 'repeated ticks must not re-apply the same version');
    }

    public function testNewerCommandSupersedesThePrevious(): void
    {
        $storage = new SpyStorage();
        $instance = $this->instance($storage);
        $spy = $this->supervisorSpy();

        $this->apply($instance, $spy);
        $storage->pushCommand(StorageInterface::CMD_PAUSE);
        $this->apply($instance, $spy);
        $storage->pushCommand(StorageInterface::CMD_CONTINUE);
        $this->apply($instance, $spy);

        self::assertSame(['pause', 'continue'], $spy->applied);
    }

    public function testAnInstanceStartingIntoAPausedClusterPausesItself(): void
    {
        $storage = new SpyStorage();
        $storage->pushCommand(StorageInterface::CMD_PAUSE);

        // A host that boots while the cluster is already paused must not start
        // consuming just because it missed the original broadcast.
        $late = $this->instance($storage);
        $spy = $this->supervisorSpy();

        self::assertSame(['pause'], $this->apply($late, $spy));
    }

    public function testAnInstanceStartingAfterATerminateDoesNotReplayIt(): void
    {
        $storage = new SpyStorage();
        $storage->pushCommand(StorageInterface::CMD_TERMINATE);

        $fresh = $this->instance($storage);
        $spy = $this->supervisorSpy();
        $this->apply($fresh, $spy);

        $shouldTerminate = new \ReflectionProperty($fresh, 'shouldTerminate');
        self::assertFalse($shouldTerminate->getValue($fresh), 'a past terminate must not kill a newly started instance');
    }

    public function testUnreachableControlStateDoesNotStopTheSupervisor(): void
    {
        $broken = new BrokenStorage();
        $instance = $this->instance($broken);
        $spy = $this->supervisorSpy();

        // Must not throw: the supervisor keeps running on its current state.
        self::assertSame([], $this->apply($instance, $spy));
    }
}
