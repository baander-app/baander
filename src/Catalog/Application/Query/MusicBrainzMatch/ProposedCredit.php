<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\ArtistRole;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An artist credit a match would give a song or album, and the local artist it would resolve to. */
#[Exclude]
final readonly class ProposedCredit
{
    /**
     * @param ArtistRole $role                Primary for the first artist MusicBrainz credits, Featured for the rest
     * @param string     $creditedName        the name the credit would show: the item's own credited name for the
     *                                        primary artist when it has one, else the name MusicBrainz credits
     * @param string     $musicbrainzArtistId the credited artist's MusicBrainz ID
     */
    public function __construct(
        public ArtistRole $role,
        public string $creditedName,
        public string $musicbrainzArtistId,
        public PredictedArtist $artist,
    ) {
    }
}
