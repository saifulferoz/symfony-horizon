<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Records when a message was handed to a transport, so the worker can compute
 * how long it waited in the queue.
 */
final class DispatchedAtStamp implements StampInterface
{
    public readonly float $dispatchedAt;

    public function __construct(?float $dispatchedAt = null)
    {
        $this->dispatchedAt = $dispatchedAt ?? microtime(true);
    }
}
