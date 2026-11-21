<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use InvalidArgumentException;

final readonly class DirectorCredit
{
    public function __construct(
        public int $tmdbId,
        public string $name,
        public ?string $profileUrl = null,
    ) {
        if ($tmdbId < 1 || trim($name) === '') {
            throw new InvalidArgumentException('A director requires a positive TMDB person ID and a name.');
        }
    }
}
