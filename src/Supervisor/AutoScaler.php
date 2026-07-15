<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Supervisor;

/**
 * Pure scaling math: how many worker processes a supervisor should run.
 */
final class AutoScaler
{
    /**
     * @param int|null $pending  messages waiting across the supervisor's transports (null = unknown)
     * @param int      $scaleFactor pending messages one worker is expected to absorb
     */
    public function desiredProcesses(?int $pending, int $min, int $max, int $scaleFactor): int
    {
        $max = max($min, $max);

        if ($pending === null) {
            return $min;
        }

        $target = (int) ceil($pending / max(1, $scaleFactor));

        return max($min, min($max, $target));
    }
}
