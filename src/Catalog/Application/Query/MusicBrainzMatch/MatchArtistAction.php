<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * An artist a match would merge into another or remove, ticked by default. Unticking it keeps the
 * artist as it is.
 *
 * An album or song comparison lists an artist that the ticked credit rows would leave without any
 * credit: it merges into the artist that takes over its primary credits, or is removed when it held
 * none. The action stands only while every credit row it requires is ticked; unticking one keeps
 * the artist's credit there, and the action goes. An artist comparison lists a merge into the
 * artist that already holds the candidate's MusicBrainz ID; that merge is how the ID is applied.
 */
#[Exclude]
final readonly class MatchArtistAction
{
    /**
     * @param string               $artistPublicId       the artist merged or removed
     * @param PredictedArtist|null $target               the artist a merge goes into, which may be one the match creates; null for a removal
     * @param list<string>         $requiredSongs        the public IDs of the songs whose credit rows must all be ticked
     * @param bool                 $requiresAlbumCredits whether the album's credit row must be ticked too
     */
    public function __construct(
        public ArtistActionKind $kind,
        public string $artistPublicId,
        public string $artistName,
        public ?PredictedArtist $target,
        public array $requiredSongs = [],
        public bool $requiresAlbumCredits = false,
        public bool $ticked = true,
    ) {
    }
}
