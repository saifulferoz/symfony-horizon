<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tags;

interface TaggableInterface
{
    /**
     * Dynamic, per-instance tags (e.g. ["user:42"]); merged with #[HorizonTags] class tags.
     *
     * @return list<string>
     */
    public function horizonTags(): array;
}
