<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Deletes an artist, its song and album credits, and its cover image. Songs and albums stay.
 */
final readonly class DeleteArtistCommand
{
    /** @param string $publicId the artist's public ID, as given */
    public function __construct(
        public string $publicId,
    ) {
    }
}
