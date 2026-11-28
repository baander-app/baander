<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoOrdering
{
    /**
     * @param string $id stable identifier for selecting this provider ordering
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
    ) {}
}
