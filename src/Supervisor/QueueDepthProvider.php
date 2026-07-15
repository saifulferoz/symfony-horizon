<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Supervisor;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * Reads pending message counts from Messenger transports that support it
 * (Doctrine, Redis, AMQP, ...). Unknown counts are reported as null so the
 * autoscaler can fall back to min_processes instead of guessing.
 */
final class QueueDepthProvider
{
    public function __construct(private readonly ContainerInterface $receiverLocator)
    {
    }

    /**
     * @param list<string> $transports
     *
     * @return array<string, int|null>
     */
    public function counts(array $transports): array
    {
        $counts = [];
        foreach ($transports as $name) {
            $counts[$name] = null;
            if (!$this->receiverLocator->has($name)) {
                continue;
            }

            $receiver = $this->receiverLocator->get($name);
            if ($receiver instanceof MessageCountAwareInterface) {
                try {
                    $counts[$name] = $receiver->getMessageCount();
                } catch (\Throwable) {
                    // Transport unreachable right now; treat as unknown.
                }
            }
        }

        return $counts;
    }

    /**
     * @param list<string> $transports
     */
    public function total(array $transports): ?int
    {
        $total = null;
        foreach ($this->counts($transports) as $count) {
            if ($count !== null) {
                $total = ($total ?? 0) + $count;
            }
        }

        return $total;
    }
}
