<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The answer to CompareMatchQuery: everything a match of the item with the candidate would set,
 * each row with the value the admin sees now and its default tick, and the artists it would merge
 * or remove.
 */
#[Exclude]
final readonly class MatchComparison
{
    /**
     * @param string                  $publicId      the item's public ID
     * @param string                  $candidateId   the MusicBrainz ID compared: a release, recording or artist
     * @param list<MatchFieldRow>     $fields        the item's own fields
     * @param MatchCreditsRow|null    $credits       the album's or song's own credits; null for an artist
     * @param list<MatchTrack>        $tracks        an album's songs and the release's tracks: in release order, then
     *                                               the local songs that pair with no track; empty for a song or artist
     * @param list<MatchArtistAction> $artistActions
     */
    public function __construct(
        public MatchItemType $type,
        public string $publicId,
        public string $candidateId,
        public array $fields,
        public ?MatchCreditsRow $credits,
        public array $tracks = [],
        public array $artistActions = [],
    ) {
    }

    /** The row of one of the item's own fields; null when the comparison has none for it. */
    public function field(MatchField $field): ?MatchFieldRow
    {
        return array_find($this->fields, static fn (MatchFieldRow $row): bool => $row->field === $field);
    }
}
