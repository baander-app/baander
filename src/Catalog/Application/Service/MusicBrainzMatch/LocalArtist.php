<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A library artist as matching compares it with MusicBrainz artists. */
#[Exclude]
final readonly class LocalArtist
{
    /**
     * @param string|null $disambiguation the artist's stored disambiguation, such as "US rapper"
     */
    public function __construct(
        public string $name,
        public ?string $disambiguation = null,
    ) {
    }
}
