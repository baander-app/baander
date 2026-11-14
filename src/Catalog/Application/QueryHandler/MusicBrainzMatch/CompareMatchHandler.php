<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles CompareMatchQuery: reads the item, looks the candidate up by its ID once, and compares
 * them. An album compares with the release alone, its tracks paired with the album's songs; the
 * comparison changes nothing.
 */
#[AsMessageHandler]
final readonly class CompareMatchHandler
{
    public function __construct(
        private MusicBrainzLookupInterface $musicBrainz,
        private CandidateScorer $scorer,
        private MatchSnapshots $snapshots,
        private MatchComparisonBuilder $comparisons,
    ) {
    }

    /**
     * @throws InvalidInputException       when the public ID or the candidate's MusicBrainz ID is malformed
     * @throws NotFoundException           when no such item exists, or MusicBrainz has no such candidate
     * @throws ServiceUnavailableException when MusicBrainz did not answer (`musicbrainz_unavailable`)
     * @throws ConflictException           when MusicBrainz rejected the request (`musicbrainz_rejected`)
     */
    public function __invoke(CompareMatchQuery $query): MatchComparison
    {
        $candidateId = MusicbrainzId::tryFromString($query->candidateId)?->toString()
            ?? throw new InvalidInputException('Invalid MusicBrainz ID format.');

        return match ($query->type) {
            MatchItemType::Album => $this->compareAlbum($query->publicId, $candidateId),
            MatchItemType::Song => $this->compareSong($query->publicId, $candidateId),
            MatchItemType::Artist => $this->compareArtist($query->publicId, $candidateId),
        };
    }

    private function compareAlbum(string $publicId, string $releaseId): MatchComparison
    {
        $snapshot = $this->snapshots->album($publicId);
        $release = ($this->musicBrainz->fetchRelease($releaseId) |> MusicBrainzAnswer::unwrap(...))
            ?? throw self::unknownCandidate('release', $releaseId);
        $scored = $this->scorer->scoreRelease($release, $snapshot->localAlbum());

        return $this->comparisons->album($snapshot, new MatchCandidate($release, $scored->score, $scored->pairs, [$release]));
    }

    private function compareSong(string $publicId, string $recordingId): MatchComparison
    {
        $snapshot = $this->snapshots->song($publicId);
        $recording = ($this->musicBrainz->fetchRecording($recordingId) |> MusicBrainzAnswer::unwrap(...))
            ?? throw self::unknownCandidate('recording', $recordingId);

        return $this->comparisons->song($snapshot, $recording);
    }

    private function compareArtist(string $publicId, string $artistId): MatchComparison
    {
        $artist = $this->snapshots->artist($publicId);
        $candidate = ($this->musicBrainz->fetchArtist($artistId) |> MusicBrainzAnswer::unwrap(...))
            ?? throw self::unknownCandidate('artist', $artistId);

        return $this->comparisons->artist($artist, $candidate);
    }

    private static function unknownCandidate(string $entity, string $id): NotFoundException
    {
        return new NotFoundException(sprintf('MusicBrainz has no %s "%s".', $entity, $id));
    }
}
