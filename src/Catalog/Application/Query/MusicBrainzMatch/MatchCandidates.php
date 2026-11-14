<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The answer to SearchMatchCandidatesQuery: what was searched, and the candidates found, best first. */
#[Exclude]
final readonly class MatchCandidates
{
    /**
     * @param string      $title  the title or artist name searched
     * @param string|null $artist the artist searched; null when the search named none
     * @param list<ReleaseCandidate>|list<RecordingCandidate>|list<ArtistCandidate> $candidates
     *        at most five, best fit first: releases for an album, recordings for a song, artists for an artist
     */
    public function __construct(
        public MatchItemType $type,
        public string $publicId,
        public string $title,
        public ?string $artist,
        public array $candidates,
    ) {
    }
}
