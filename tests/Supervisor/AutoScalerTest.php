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
}
