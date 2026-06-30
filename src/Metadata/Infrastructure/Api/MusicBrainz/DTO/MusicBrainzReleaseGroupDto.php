<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz\DTO;

/**
 * Immutable DTO representing a MusicBrainz release group.
 */
final readonly class MusicBrainzReleaseGroupDto
{
    /**
     * @param string $id MusicBrainz ID (MBID)
     * @param string $title Release group title
     * @param string|null $primaryType Primary type (Album, Single, EP, etc.)
     * @param string[] $secondaryTypes Secondary types
     * @param string|null $firstReleaseDate Earliest release date
     * @param string $artistCredit Human-readable artist credit string
     * @param string[] $tags User-applied tags
     * @param int $score Search relevance score (0 when not from a search)
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $primaryType = null,
        public array $secondaryTypes = [],
        public ?string $firstReleaseDate = null,
        public string $artistCredit = '',
        public array $tags = [],
        public int $score = 0,
    ) {
    }
}
