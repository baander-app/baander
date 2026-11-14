<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * An ordered MusicBrainz artist credit, such as "Post Malone feat. Luke Combs".
 *
 * MusicBrainz marks no roles; only the free-text join phrases tell a guest from a co-lead.
 * Baander's convention is that the first artist is the primary one and the rest are featured.
 */
final readonly class MusicBrainzArtistCredit
{
    /**
     * @param list<MusicBrainzCreditedArtist> $artists in credit order
     */
    public function __construct(
        public array $artists,
    ) {
    }

    /** The credit as printed: each credited name followed by its join phrase. */
    public function text(): string
    {
        return array_map(static fn(MusicBrainzCreditedArtist $artist): string => $artist->name . $artist->joinPhrase, $this->artists)
            |> implode(...);
    }
}
