<?php

namespace Saifulferoz\SymfonyHorizon\Supervisor;

class Autoscaler
{
    /**
     * Determines the optimal number of worker processes based on the backlog.
     */
    public function scale(int $currentProcesses, int $pendingMessages, int $minProcesses, int $maxProcesses): int
    {
        if ($pendingMessages <= 0) {
            return $minProcesses;
        }

        // Scale formula: 1 extra worker for every 50 pending messages
        $additionalNeeded = (int) ceil($pendingMessages / 50);
        $target = $minProcesses + $additionalNeeded;

        return (int) max($minProcesses, min($maxProcesses, $target));
    }
}
