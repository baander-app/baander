<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A MusicBrainz artist: a person, group or other performer. */
final readonly class MusicBrainzArtist
{
    /**
     * @param string $id the artist's MusicBrainz ID
     * @param int $score MusicBrainz's text relevance from 0 to 100; 0 when not from a search
     * @param string|null $sortName such as "Malone, Post"
     * @param string|null $type such as "Person" or "Group"
     * @param string|null $country an ISO 3166-1 code
     * @param string|null $lifeSpanBegin when the artist was born or began (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null $lifeSpanEnd when the artist died or ended
     * @param list<string> $aliases
     * @param array<string, string> $personIdentifiers
     * @param bool $ended whether the artist has ended, even when the end date is unknown
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $score,
        public ?string $sortName,
        public ?string $type,
        public ?string $country,
        public ?string $disambiguation,
        public ?string $lifeSpanBegin,
        public ?string $lifeSpanEnd,
        public bool $ended,
        public ?string $gender = null,
        public ?string $birthplace = null,
        public array $aliases = [],
        public array $personIdentifiers = [],
    ) {
    }
}
