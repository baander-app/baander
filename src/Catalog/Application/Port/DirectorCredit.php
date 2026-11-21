<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

final readonly class DirectorCredit
{
    public function __construct(
        public int $tmdbId,
        public string $name,
        public ?string $profileUrl = null,
    ) {}
}
