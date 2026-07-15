<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tags;

/**
 * Tag a message class so its jobs are searchable on the Horizon dashboard.
 *
 *     #[HorizonTags('billing', 'critical')]
 *     final class ChargeInvoice { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HorizonTags
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}
