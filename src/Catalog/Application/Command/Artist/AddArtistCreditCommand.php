<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Credits an artist on a song or an album with a role. Adding a credit the artist already has
 * changes nothing.
 */
final readonly class AddArtistCreditCommand
{
    /**
     * @param string $artistPublicId the artist's public ID, as given
     * @param string $targetId       the song's or album's UUID, as given
     * @param string $role           an ArtistRole value, as given
     */
    public function __construct(
        public string $artistPublicId,
        public CreditTarget $target,
        public string $targetId,
        public string $role,
    ) {
    }
}
