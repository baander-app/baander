<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Applies a MusicBrainz candidate to an album, song or artist.
 *
 * A manual apply writes the rows an admin ticked in the comparison of the item with the candidate,
 * once it has looked the candidate up again by its ID and found every row still showing what the
 * admin saw. An automatic apply matches an album without an admin: it fills the album and the songs
 * the candidate pairs from the candidate the matcher decided on, writing MusicBrainz IDs, artist
 * credits and the merges and removals they cause, empty fields and a missing cover, and never a
 * field that holds a value or is locked. Either kind moves an album's match time to the apply when
 * it records the release, or when the album holds that release already, as when the matcher pairs
 * a matched album's new songs against its stored release.
 */
#[Exclude]
final readonly class ApplyMatchCommand
{
    /**
     * @param string|null $publicId          the item's public ID, as given; null for an automatic apply
     * @param string      $candidateId       the candidate's MusicBrainz ID: a release for an album, a recording for a song, an artist for an artist
     * @param Uuid|null   $albumId           the album an automatic apply matches
     * @param string|null $releaseIdSeen     the release ID the album held when the matcher read it, for an automatic apply
     */
    private function __construct(
        public MatchItemType $type,
        public ?string $publicId,
        public string $candidateId,
        public ?MatchSelection $selection,
        public ?Uuid $albumId = null,
        public ?MatchCandidate $candidate = null,
        public ?string $releaseIdSeen = null,
    ) {
    }

    /**
     * @param string $publicId    the item's public ID, as given
     * @param string $candidateId the candidate's MusicBrainz ID, as given
     */
    public static function manual(MatchItemType $type, string $publicId, string $candidateId, MatchSelection $selection): self
    {
        return new self($type, $publicId, $candidateId, $selection);
    }

    /**
     * @param MatchCandidate $candidate     the release the matcher decided on, with its twin editions and the songs it pairs:
     *                                      for a matched album, its stored release and the songs created since its match time
     * @param string|null    $releaseIdSeen the release ID the album held when the matcher read it, null for an unmatched album;
     *                                      the apply writes nothing when the album holds another one by then
     */
    public static function automatic(Uuid $albumId, MatchCandidate $candidate, ?string $releaseIdSeen = null): self
    {
        return new self(MatchItemType::Album, null, $candidate->release->id, null, $albumId, $candidate, $releaseIdSeen);
    }

    /**
     * @phpstan-assert-if-true !null $this->albumId
     * @phpstan-assert-if-true !null $this->candidate
     * @phpstan-assert-if-false !null $this->publicId
     * @phpstan-assert-if-false !null $this->selection
     */
    public function isAutomatic(): bool
    {
        return $this->candidate !== null;
    }
}
