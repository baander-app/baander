<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * Compares an album, song or artist with the MusicBrainz candidate an admin picked: what a match
 * would set, field by field and track by track, and the artists it would merge or remove. Changes
 * nothing.
 */
final readonly class CompareMatchQuery
{
    /**
     * @param string $publicId    the item's public ID, as given
     * @param string $candidateId the candidate's MusicBrainz ID: a release for an album, a recording for a song, an artist for an artist
     */
    public function __construct(
        public MatchItemType $type,
        public string $publicId,
        public string $candidateId,
    ) {
    }
}
