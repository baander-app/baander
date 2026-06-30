<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz\DTO;

/**
 * Immutable DTO holding the results of a MusicBrainz search query.
 *
 * Each search endpoint returns the same envelope structure with count/offset,
 * but only one of the entity arrays will be populated per call.
 */
final readonly class MusicBrainzSearchResultDto
{
    /**
     * @param MusicBrainzArtistDto[] $artists Matched artists
     * @param MusicBrainzReleaseGroupDto[] $releaseGroups Matched release groups
     * @param MusicBrainzRecordingDto[] $recordings Matched recordings
     * @param int $total Total number of matching results across all pages
     */
    public function __construct(
        public array $artists = [],
        public array $releaseGroups = [],
        public array $recordings = [],
        public int $total = 0,
    ) {
    }
}
