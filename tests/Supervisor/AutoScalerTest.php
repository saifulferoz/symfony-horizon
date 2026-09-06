<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Supervisor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;

final class AutoScalerTest extends TestCase
{
    /** @return iterable<string, array{0: int|null, 1: int, 2: int, 3: int, 4: int}> */
    public static function scalingCases(): iterable
    {
        // pending, min, max, scaleFactor, expected
        yield 'empty queue stays at min' => [0, 1, 10, 10, 1];
        yield 'unknown depth falls back to min' => [null, 2, 10, 10, 2];
        yield 'scales up with backlog' => [45, 1, 10, 10, 5];
        yield 'rounds partial workers up' => [11, 1, 10, 10, 2];
        yield 'caps at max' => [500, 1, 10, 10, 10];
        yield 'never below min' => [5, 3, 10, 10, 3];
        yield 'min zero allows scale to zero' => [0, 0, 10, 10, 0];
        yield 'max below min is lifted to min' => [500, 5, 2, 10, 5];
        yield 'scale factor of one is one worker per message' => [3, 1, 10, 1, 3];
    }

    #[DataProvider('scalingCases')]
    public function testDesiredProcesses(?int $pending, int $min, int $max, int $scaleFactor, int $expected): void
    {
        self::assertSame($expected, (new AutoScaler())->desiredProcesses($pending, $min, $max, $scaleFactor));
    }

    public function testScalesUpImmediately(): void
    {
        // Backlog is waiting: jump straight to target rather than one step.
        self::assertSame(5, (new AutoScaler())->desiredProcesses(45, 1, 10, 10, current: 1));
    }

    public function testScalesDownOneWorkerAtATime(): void
    {
        $scaler = new AutoScaler();

        // Target is 1, but the pool is at 5: step down gradually.
        self::assertSame(4, $scaler->desiredProcesses(5, 1, 10, 10, current: 5));
        self::assertSame(3, $scaler->desiredProcesses(5, 1, 10, 10, current: 4));
        self::assertSame(2, $scaler->desiredProcesses(5, 1, 10, 10, current: 3));
        self::assertSame(1, $scaler->desiredProcesses(5, 1, 10, 10, current: 2));
        self::assertSame(1, $scaler->desiredProcesses(5, 1, 10, 10, current: 1), 'never below the target');
    }

    public function testHoldsSteadyWhenTargetMatchesCurrent(): void
    {
        self::assertSame(3, (new AutoScaler())->desiredProcesses(25, 1, 10, 10, current: 3));
    }

    public function testDampingNeverOvershootsTheTarget(): void
    {
        // A dip to target=4 from 5 must land on 4, not 4-1.
        self::assertSame(4, (new AutoScaler())->desiredProcesses(35, 1, 10, 10, current: 5));
    }
}
