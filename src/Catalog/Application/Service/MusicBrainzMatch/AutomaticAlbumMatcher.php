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
 * An unmatched album runs the search an admin's candidate list runs for the album's own title: a
 * release search on its title and primary artist, repeated once without the comma-separated list
 * in square brackets at the title's end when it has one and finds no release. The hits are ranked
 * by title, artist and track count, and the best five are looked up and scored by their tracks
 * too, all against the title that found them. MatchConfidence decides; a confident candidate is
 * applied in fill-only automatic mode, which leaves the stored title as it is. A matched album
 * looks up its stored release only and pairs the songs no match has compared yet, the album's
 * other songs holding the tracks they pair with.
 *
 * Every MusicBrainz call waits for its turn as long as the queue ahead of it needs. Nothing is
 * written when something stands in the way, and the outcome says who waits: a MusicBrainz outage
 * is unavailable, so every album waits; an apply that met changed rows or a deadlock retries this
 * album after a quarter of an hour, and a request MusicBrainz refused retries it after six hours.
 * A cover the Cover Art Archive refuses stands in no one's way: the match is applied without it.
 */
final readonly class AutomaticAlbumMatcher implements AutomaticAlbumMatcherInterface
{
    /** How many release search hits are looked up, as for an admin's candidate list. */
    private const int LOOKUP_LIMIT = 5;

    /** How long an album waits after its apply met changed rows or a deadlock. */
    private const int RETRY_AFTER_CONFLICT_SECONDS = 900;

    /** How long an album waits after MusicBrainz refused a request about it. */
    private const int RETRY_AFTER_REFUSAL_SECONDS = 21_600;

    public function __construct(
        private AlbumPortInterface $albums,
        private MatchSnapshots $snapshots,
        private MusicBrainzLookupInterface $musicBrainz,
        private AlbumReleaseSearch $releaseSearch,
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
                ? AutomaticMatchOutcome::retryLater(self::RETRY_AFTER_REFUSAL_SECONDS)
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
        $found = $this->releaseSearch->find($snapshot->album->getTitle(), $snapshot->primaryArtistName(), MusicBrainzSlotWait::Unbounded);
        $album = $snapshot->localAlbum($found->title);
        $hits = $found->hits;
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
     * Pairs the songs of a matched album that no match has compared yet against its stored release.
     * The album's other songs pair first, so a new song takes only a track no older song holds. When
     * MusicBrainz no longer has the release, the album is left for review without candidates.
     */
    private function newSongs(AlbumSnapshot $snapshot, string $releaseId): MatchDecision
    {
        $release = $this->lookUp($releaseId);
        if ($release === null) {
            return MatchDecision::needsReview(AlbumMatchReviewReason::NoCandidates, null);
        }

        // A song imported while a match ran was not compared by it, whenever it was created.
        $new = array_filter($snapshot->songs, static fn (Song $song): bool => $song->getMatchCheckedAt() === null)
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
            return $cause instanceof TransactionDeadlockException ? AutomaticMatchOutcome::retryLater(self::RETRY_AFTER_CONFLICT_SECONDS) : throw $cause;
        }
        if (!$result instanceof MatchApplyResult) {
            throw new \LogicException('Applying a match returns its result.');
        }

        return match ($result->outcome) {
            // An admin matched the album while MusicBrainz was asked; nothing is left to do.
            MatchApplyOutcome::Applied, MatchApplyOutcome::AlbumMatchedMeanwhile => AutomaticMatchOutcome::matched(),
            MatchApplyOutcome::AlbumGone => AutomaticMatchOutcome::albumGone(),
            MatchApplyOutcome::Stale => AutomaticMatchOutcome::retryLater(self::RETRY_AFTER_CONFLICT_SECONDS),
            MatchApplyOutcome::Unavailable => AutomaticMatchOutcome::unavailable(),
        };
    }
}
