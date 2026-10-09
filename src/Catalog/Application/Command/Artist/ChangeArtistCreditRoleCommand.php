<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Changes the role of one of an artist's credits on a song or an album.
 *
 * The current role names the credit to change. It may be left out when the artist has a single
 * credit on the target; with several it is required.
 */
final readonly class ChangeArtistCreditRoleCommand
{
    /**
     * @param string      $artistPublicId the artist's public ID, as given
     * @param string      $targetId       the song's or album's UUID, as given
     * @param string      $role           the new ArtistRole value, as given
     * @param string|null $currentRole    the ArtistRole value of the credit to change, as given
     */
    public function __construct(
        public string $artistPublicId,
        public CreditTarget $target,
        public string $targetId,
        public string $role,
        public ?string $currentRole = null,
    ) {
    }
}
