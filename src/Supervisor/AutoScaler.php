<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Supervisor;

/**
 * Pure scaling math: how many worker processes a supervisor should run.
 *
 * Scaling is deliberately asymmetric — up in one step, down one worker at a
 * time. Queue depth oscillates around a scale_factor boundary, and symmetric
 * scaling would churn workers (each restart costs a cold kernel boot and drops
 * the worker's warm state) for no throughput gain.
 */
final class AutoScaler
{
    /**
     * @param int|null $pending     messages waiting across the supervisor's transports (null = unknown)
     * @param int      $scaleFactor pending messages one worker is expected to absorb
     * @param int|null $current     processes running now; null when unknown (first tick)
     */
    public function desiredProcesses(?int $pending, int $min, int $max, int $scaleFactor, ?int $current = null): int
    {
        $max = max($min, $max);

        if ($pending === null) {
            return $min;
        }

        $target = max($min, min($max, (int) ceil($pending / max(1, $scaleFactor))));

        if ($current === null || $target >= $current) {
            return $target; // scale up (or hold) immediately: backlog is waiting
        }

        // Scale down gradually so a brief dip does not tear down the pool.
        return max($target, $current - 1);
    }
}
