<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Removes every credit an artist has on a song or an album, whatever its role.
 */
final readonly class RemoveArtistCreditCommand
{
    /**
     * @param string $artistPublicId the artist's public ID, as given
     * @param string $targetId       the song's or album's UUID, as given
     */
    public function __construct(
        public string $artistPublicId,
        public CreditTarget $target,
        public string $targetId,
    ) {
    }
}
