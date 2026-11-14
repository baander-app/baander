<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A MusicBrainz artist a library artist may match. */
#[Exclude]
final readonly class ArtistCandidate
{
    /**
     * @param string      $id            the artist's MusicBrainz ID, which CompareMatchQuery takes
     * @param int<0, 100> $score         how well the artist fits, by name and, when the library artist has one, disambiguation
     * @param string|null $type          such as "Person" or "Group"
     * @param string|null $country       an ISO 3166-1 code
     * @param string|null $lifeSpanBegin when the artist was born or began (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null $lifeSpanEnd   when the artist died or ended
     * @param bool        $ended         whether the artist has ended, even when the end date is unknown
     */
    public function __construct(
        public string $id,
        public int $score,
        public string $name,
        public ?string $sortName,
        public ?string $disambiguation,
        public ?string $type,
        public ?string $country,
        public ?string $lifeSpanBegin,
        public ?string $lifeSpanEnd,
        public bool $ended,
    ) {
    }

    /** @param int<0, 100> $score */
    public static function of(MusicBrainzArtist $artist, int $score): self
    {
        return new self(
            id: $artist->id,
            score: $score,
            name: $artist->name,
            sortName: $artist->sortName,
            disambiguation: $artist->disambiguation,
            type: $artist->type,
            country: $artist->country,
            lifeSpanBegin: $artist->lifeSpanBegin,
            lifeSpanEnd: $artist->lifeSpanEnd,
            ended: $artist->ended,
        );
    }
}
