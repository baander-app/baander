<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyOutcome;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\AlbumSnapshot;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MusicBrainzAnswer;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Matches one album without an admin for the album match queue.
 *
 * An unmatched album runs the search an admin's candidate list runs: one release search on its
 * title and primary artist, the hits ranked by title, artist and track count, the best five looked
 * up and scored by their tracks too. MatchConfidence decides; a confident candidate is applied in
 * fill-only automatic mode. A matched album looks up its stored release only and pairs the songs
 * created since its last apply, the album's other songs holding the tracks they pair with.
 *
 * Every MusicBrainz call waits for its turn as long as the queue ahead of it needs. A MusicBrainz
 * outage or refusal, and an apply that met changed rows or a deadlock, write nothing and come back
 * as unavailable, so the album is matched again later.
 */
final readonly class AutomaticAlbumMatcher implements AutomaticAlbumMatcherInterface
{
    /** How many release search hits are looked up, as for an admin's candidate list. */
    private const int LOOKUP_LIMIT = 5;

    public function __construct(
        private AlbumPortInterface $albums,
        private MatchSnapshots $snapshots,
        private MusicBrainzLookupInterface $musicBrainz,
        private CandidateScorer $scorer,
        private MatchConfidence $confidence,
        private MessageBusInterface $bus,
    ) {
    }

    #[\Override]
    public function match(Uuid $albumId): AutomaticMatchOutcome
    {
        $album = $this->albums->findFreshByUuid($albumId);
        if ($album === null) {
            return AutomaticMatchOutcome::albumGone();
        }

        $snapshot = $this->snapshots->ofAlbum($album);
        $releaseId = $album->getReleaseMbid();
        try {
            $decision = $releaseId === null ? $this->search($snapshot) : $this->newSongs($snapshot, $releaseId);
        } catch (ServiceUnavailableException) {
            return AutomaticMatchOutcome::unavailable();
        } catch (ConflictException $refused) {
            return ($refused->details['reason'] ?? null) === MusicBrainzAnswer::REJECTED_REASON
                ? AutomaticMatchOutcome::unavailable()
                : throw $refused;
        }

        if (!$decision->isConfident()) {
            return AutomaticMatchOutcome::needsReview(
                $decision->reviewReason ?? AlbumMatchReviewReason::NoCandidates,
                $decision->bestScore,
                count($snapshot->songs),
            );
        }

        return ApplyMatchCommand::automatic($albumId, $decision->match, $releaseId) |> $this->apply(...);
    }

    /** Searches MusicBrainz for the album's release and decides whether the best one is confident. */
    private function search(AlbumSnapshot $snapshot): MatchDecision
    {
        $album = $snapshot->localAlbum();
        $hits = $this->musicBrainz->findReleases($snapshot->album->getTitle(), $snapshot->primaryArtistName(), MusicBrainzSlotWait::Unbounded)
            |> MusicBrainzAnswer::unwrap(...);
        $scores = array_map(fn (MusicBrainzReleaseSummary $hit): int => $this->scorer->scoreReleaseSummary($hit, $album), $hits);
        $ranked = array_keys($hits);
        usort($ranked, static fn (int $one, int $other): int => $scores[$other] <=> $scores[$one]);

        return array_slice($ranked, 0, self::LOOKUP_LIMIT)
            |> (fn (array $best): array => array_map(fn (int $index): ?MusicBrainzRelease => $this->lookUp($hits[$index]->id), $best))
            |> array_filter(...)
            |> (fn (array $releases): array => array_map(fn (MusicBrainzRelease $release): ScoredRelease => $this->scorer->scoreRelease($release, $album), $releases))
            |> array_values(...)
            |> $this->confidence->decide(...);
    }

    /**
     * Pairs the songs a matched album gained since its last apply against its stored release. The
     * album's other songs pair first, so a new song takes only a track no older song holds. When
     * MusicBrainz no longer has the release, the album is left for review without candidates.
     */
    private function newSongs(AlbumSnapshot $snapshot, string $releaseId): MatchDecision
    {
        $release = $this->lookUp($releaseId);
        if ($release === null) {
            return MatchDecision::needsReview(AlbumMatchReviewReason::NoCandidates, null);
        }

        $matchedAt = $snapshot->album->getMatchedAt();
        $new = array_filter($snapshot->songs, static fn (Song $song): bool => $matchedAt === null || $song->getCreatedAt() > $matchedAt)
            |> (static fn (array $songs): array => array_map(static fn (Song $song): string => $song->getId()->toString(), $songs))
            |> array_flip(...);
        $isNew = static fn (LocalTrack $track): bool => isset($new[$track->id]);
        $scored = $this->scorer->scoreRelease($release, $snapshot->localAlbum());
        $pairs = new TrackPairs(
            pairs: array_filter($scored->pairs->pairs, static fn (TrackPair $pair): bool => $isNew($pair->local)) |> array_values(...),
            unpairedLocal: array_filter($scored->pairs->unpairedLocal, $isNew) |> array_values(...),
            unpairedRelease: $scored->pairs->unpairedRelease,
        );

        return MatchDecision::confident(new MatchCandidate($release, $scored->score, $pairs, [$release]));
    }

    /** The release with its tracks; null when MusicBrainz no longer has it. */
    private function lookUp(string $releaseId): ?MusicBrainzRelease
    {
        return $this->musicBrainz->fetchRelease($releaseId, MusicBrainzSlotWait::Unbounded) |> MusicBrainzAnswer::unwrap(...);
    }

    /** Applies the candidate and turns the apply's outcome into the queue's. */
    private function apply(ApplyMatchCommand $command): AutomaticMatchOutcome
    {
        try {
            $result = $this->bus->dispatch($command)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $cause = HandlerFailure::cause($failed);

            // The apply wrote nothing; another writer held the same rows.
            return $cause instanceof TransactionDeadlockException ? AutomaticMatchOutcome::unavailable() : throw $cause;
        }
        if (!$result instanceof MatchApplyResult) {
            throw new \LogicException('Applying a match returns its result.');
        }

        return match ($result->outcome) {
            // An admin matched the album while MusicBrainz was asked; nothing is left to do.
            MatchApplyOutcome::Applied, MatchApplyOutcome::AlbumMatchedMeanwhile => AutomaticMatchOutcome::matched(),
            MatchApplyOutcome::AlbumGone => AutomaticMatchOutcome::albumGone(),
            MatchApplyOutcome::Stale, MatchApplyOutcome::Unavailable => AutomaticMatchOutcome::unavailable(),
        };
    }
}
