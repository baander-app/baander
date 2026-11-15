<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** How an apply ended and, when it applied, what it wrote. */
#[Exclude]
final readonly class MatchApplyResult
{
    /**
     * @param string|null  $publicId       the item's public ID; null for an automatic apply whose album is gone
     * @param string       $candidateId    the MusicBrainz ID applied: a release, recording or artist
     * @param list<string> $fields         the item's own fields written, as MatchField values
     * @param bool         $creditsChanged whether the item's own artist credits changed
     * @param int          $songsChanged   how many of an album's songs took fields or credits
     * @param list<string> $mergedArtists  the public IDs of the artists merged into another artist
     * @param list<string> $removedArtists the public IDs of the artists removed
     * @param bool         $coverRefused   whether an automatic apply left out the album's cover because the
     *                                     Cover Art Archive refused it; the API resource does not carry it
     */
    private function __construct(
        public MatchApplyOutcome $outcome,
        public MatchItemType $type,
        public ?string $publicId,
        public string $candidateId,
        public array $fields = [],
        public bool $creditsChanged = false,
        public int $songsChanged = 0,
        public array $mergedArtists = [],
        public array $removedArtists = [],
        public bool $coverRefused = false,
    ) {
    }

    /**
     * @param list<string> $fields
     * @param list<string> $mergedArtists
     * @param list<string> $removedArtists
     */
    public static function applied(
        MatchItemType $type,
        string $publicId,
        string $candidateId,
        array $fields,
        bool $creditsChanged,
        int $songsChanged,
        array $mergedArtists,
        array $removedArtists,
    ): self {
        return new self(MatchApplyOutcome::Applied, $type, $publicId, $candidateId, $fields, $creditsChanged, $songsChanged, $mergedArtists, $removedArtists);
    }

    /** An automatic apply that wrote nothing, and why. */
    public static function notApplied(MatchApplyOutcome $outcome, ?string $publicId, string $candidateId): self
    {
        return new self($outcome, MatchItemType::Album, $publicId, $candidateId);
    }

    /** The same result, saying that the match was applied without the cover the Cover Art Archive refused. */
    public function withCoverRefused(): self
    {
        return clone($this, ['coverRefused' => true]);
    }

    public function isApplied(): bool
    {
        return $this->outcome === MatchApplyOutcome::Applied;
    }
}
