<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes dashboard payloads to a Mercure hub.
 *
 * The only class in the bundle that references symfony/mercure, so the
 * dependency stays optional and contained.
 *
 * Updates are published private: the payload carries queue names, message
 * class names and failure counts, and a hub may be shared with other
 * applications. Only subscribers holding a JWT with a matching
 * mercure.subscribe claim receive them.
 */
final class MercureUpdatePublisher implements UpdatePublisherInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function publish(string $topic, array $payload): void
    {
        $data = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        if ($data === false) {
            $this->logger?->warning('Horizon could not encode a {topic} update.', ['topic' => $topic]);

            return;
        }

        try {
            $this->hub->publish(new Update($topic, $data, private: true));
        } catch (\Throwable $e) {
            // A hub outage must never break the supervisor that is publishing;
            // the dashboard detects the silence and falls back to polling.
            $this->logger?->warning('Horizon could not publish a {topic} update: {message}', [
                'topic' => $topic,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    public function isActive(): bool
    {
        return true;
    }
}
