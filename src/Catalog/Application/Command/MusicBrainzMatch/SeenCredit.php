<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** One artist credit of a song or album as the admin saw it in the comparison. */
#[Exclude]
final readonly class SeenCredit
{
    /**
     * @param string|null $role         an ArtistRole value; null for a credit without a role
     * @param string      $creditedName the name the song or album is credited under
     */
    public function __construct(
        public string $artistPublicId,
        public ?string $role,
        public string $creditedName,
    ) {
    }
}
