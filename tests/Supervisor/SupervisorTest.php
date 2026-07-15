<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Saifulferoz\SymfonyHorizon\Supervisor\Supervisor;
use Saifulferoz\SymfonyHorizon\Supervisor\WorkerPool;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class RecordingPool extends WorkerPool
{
    /** @var list<int> */
    public array $scaleCalls = [];
    public bool $stopped = false;
    private int $size = 0;

    public function __construct()
    {
        parent::__construct(['true']);
    }

    public function scaleTo(int $desired): void
    {
        $this->scaleCalls[] = $desired;
        $this->size = $desired;
    }

    public function count(): int
    {
        return $this->size;
    }

    public function reap(): int
    {
        return 0;
    }

    public function stopAll(): void
    {
        $this->stopped = true;
        $this->size = 0;
    }
}

final class SupervisorTest extends TestCase
{
    private RecordingPool $pool;
    private SpyStorage $storage;

    private function supervisor(array $config = [], int $pending = 0): Supervisor
    {
        $receiver = new class($pending) implements MessageCountAwareInterface {
            public function __construct(private readonly int $pending)
            {
            }

            public function getMessageCount(): int
            {
                return $this->pending;
            }
        };

        $locator = new class(['async' => $receiver]) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        $this->pool = new RecordingPool();
        $this->storage = new SpyStorage();

        return new Supervisor(
            'default',
            $config + [
                'transports' => ['async'],
                'min_processes' => 1,
                'max_processes' => 5,
                'balance' => 'auto',
                'scale_factor' => 10,
                'autoscale_cooldown' => 3,
            ],
            $this->pool,
            new AutoScaler(),
            new QueueDepthProvider($locator),
            $this->storage,
        );
    }

    public function testScalesUpOnBacklog(): void
    {
        $supervisor = $this->supervisor(pending: 42);

        $supervisor->tick();

        self::assertSame([5], $this->pool->scaleCalls, 'ceil(42/10) = 5 workers');
    }

    public function testBalanceOffPinsToMinProcesses(): void
    {
        $supervisor = $this->supervisor(['balance' => 'off', 'min_processes' => 2], pending: 500);

        $supervisor->tick();

        self::assertSame([2], $this->pool->scaleCalls);
    }

    public function testCooldownPreventsImmediateRescale(): void
    {
        $supervisor = $this->supervisor(pending: 42);

        $supervisor->tick();
        $supervisor->tick();

        self::assertCount(1, $this->pool->scaleCalls, 'second tick inside the cooldown must not rescale');
    }

    public function testPauseStopsWorkersAndResumeRestoresThem(): void
    {
        $supervisor = $this->supervisor(pending: 42);

        $supervisor->tick();
        $supervisor->pause();
        self::assertSame(0, $this->pool->count());
        self::assertTrue($supervisor->isPaused());

        // ticking while paused must not restart anything
        $supervisor->tick();
        self::assertSame([5, 0], $this->pool->scaleCalls);

        $supervisor->resume();
        $supervisor->tick();
        self::assertSame([5, 0, 5], $this->pool->scaleCalls);
    }

    public function testHeartbeatReportsStatusAndPending(): void
    {
        $supervisor = $this->supervisor(pending: 42);

        $supervisor->tick();

        self::assertNotSame([], $this->storage->supervisorHeartbeats);
        [$name, $meta] = $this->storage->supervisorHeartbeats[0];
        self::assertSame('default', $name);
        self::assertSame('running', $meta['status']);
        self::assertSame('5', $meta['processes']);
        self::assertSame(['async' => 42], json_decode($meta['pending'], true));
    }

    public function testStopShutsDownPoolAndRemovesHeartbeat(): void
    {
        $supervisor = $this->supervisor(pending: 42);
        $supervisor->tick();

        $supervisor->stop();

        self::assertTrue($this->pool->stopped);
        self::assertSame(['default'], $this->storage->removedSupervisors);
    }
}
