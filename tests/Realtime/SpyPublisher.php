<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Realtime;

use Saifulferoz\SymfonyHorizon\Realtime\UpdatePublisherInterface;

final class SpyPublisher implements UpdatePublisherInterface
{
    /** @var list<array{topic: string, payload: array<string, mixed>}> */
    public array $published = [];

    public function __construct(private readonly bool $active = true)
    {
    }

    public function publish(string $topic, array $payload): void
    {
        $this->published[] = ['topic' => $topic, 'payload' => $payload];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<string> */
    public function topics(): array
    {
        return array_column($this->published, 'topic');
    }
}
