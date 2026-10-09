<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Creates an artist that no scan found, such as a featured performer to credit by hand.
 */
final readonly class CreateArtistCommand
{
    public function __construct(
        public string $name,
        public ?string $country = null,
        public ?string $gender = null,
        public ?string $type = null,
        public ?string $disambiguation = null,
        public ?string $sortName = null,
        public ?string $biography = null,
    ) {
    }
}
