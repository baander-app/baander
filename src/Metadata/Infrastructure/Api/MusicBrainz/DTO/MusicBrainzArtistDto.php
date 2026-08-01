<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz\DTO;

/**
 * Immutable DTO representing a MusicBrainz artist.
 */
final readonly class MusicBrainzArtistDto
{
    /**
     * @param string $id MusicBrainz ID (MBID)
     * @param string $name Artist name
     * @param string|null $sortName Sort name (e.g. "Beatles, The")
     * @param string|null $type Artist type (Person, Group, Orchestra, Choir, Character, Other)
     * @param string|null $country ISO 3166-1 country code
     * @param string|null $disambiguation Disambiguation comment
     * @param string|null $lifeSpanBegin Begin date (YYYY, YYYY-MM, or YYYY-MM-DD)
     * @param string|null $lifeSpanEnd End date (null if still active)
     * @param string[] $tags User-applied tags
     * @param int $score Search relevance score (0 when not from a search)
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $sortName = null,
        public ?string $type = null,
        public ?string $country = null,
        public ?string $disambiguation = null,
        public ?string $lifeSpanBegin = null,
        public ?string $lifeSpanEnd = null,
        public array $tags = [],
        public int $score = 0,
    ) {
    }
}
