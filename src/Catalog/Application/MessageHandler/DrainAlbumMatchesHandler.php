<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchQueueChain;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcherInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcomeKind;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs the album match queue one album per message, so other work on the async transport runs
 * between two albums.
 *
 * Each run claims the request that has been due longest, under a claim ID of its own, and matches
 * its album without an admin. The claim holds the album until the run ends it: no other run takes
 * the album meanwhile, and a request made for it meanwhile waits beside the claim. An import's
 * request is removed instead while automatic matching is off; admin requests are matched whatever
 * the switch says. A confident album is matched, any other album is marked for review. An album
 * whose apply met changed rows or a deadlock, or about which MusicBrainz refused a request, is
 * given back due later while the queue goes on with the next album; so is an album whose match
 * failed with an error, which is logged and tried again an hour later. A MusicBrainz outage gives
 * the request back as it was and pauses the queue for 5, 15 and 60 minutes, then hourly.
 *
 * When a request waited beside the claim, the album waits for a match again from that request's
 * due time, whatever the match found; only an outage drops it, since the album is still due from
 * before. While requests wait, the run queues the next one: a second later, or when the next
 * request can be taken. A run that dies leaves its album held until the claim lapses.
 *
 * The runs form one chain (AlbumMatchQueueChain). A run without a chain starts one in place of any
 * other; a run of a chain that was replaced ends at once, without matching or queuing a successor.
 * A run whose chain was replaced while it held its album ends its claim as usual. When that leaves
 * its album waiting, it queues a run without a chain for the album's due time, a second ahead at
 * the soonest, unless another request falls due at or before it: the newer chain counted the album
 * from its claim's expiry. After an outage such a run queues nothing: the album keeps its earlier
 * due time and a request made during the claim is dropped, yet the album is not stranded, since the
 * newer chain keeps a run on its way while any request waits.
 */
final readonly class DrainAlbumMatchesHandler
{
    /** The pause between two albums, which lets other queued work run in between. */
    private const int NEXT_RUN_DELAY_MS = 1_000;

    /** The pauses after one, two and three or more outages in a row. */
    private const array OUTAGE_DELAYS_MS = [300_000, 900_000, 3_600_000];

    /**
     * How long a run holds the request it took, and its chain while it matches. After a run that
     * died, both lapse together: another run can take the request, and the queue's recovery can
     * start one.
     */
    private const int CLAIM_SECONDS = 900;

    /** How long an album waits after its match failed with an error. */
    private const int FAILURE_RETRY_SECONDS = 3_600;

    /** How much longer than its next run's delay a chain holds the queue, for a worker that is late. */
    private const int CHAIN_MARGIN_SECONDS = 900;

    public function __construct(
        private AlbumMatchStateRepositoryInterface $states,
        private AutomaticAlbumMatcherInterface $matcher,
        private AlbumMatchQueueChain $chains,
        private SystemSettingsPortInterface $settings,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(DrainAlbumMatchesMessage $message): void
    {
        $chain = $message->chain;
        if ($chain === null) {
            $chain = $this->chains->start(self::CLAIM_SECONDS);
        } elseif (!$this->chains->hold($chain, self::CLAIM_SECONDS)) {
            // A newer chain works the queue.
            return;
        }

        $claimId = Uuid::v7();
        $request = $this->states->claimOldestDueRequest($this->clock->now(), $claimId, self::CLAIM_SECONDS);
        if ($request === null) {
            $this->queueNextRun($chain);

            return;
        }

        if ($request->source === AlbumMatchSource::Ingest && $this->settings->get(MetadataSettingDefinitions::AUTO_SYNC) !== true) {
            $this->states->removeIngestRequests();
            // An admin request made during the claim turned this request into an admin request,
            // which the removal leaves in place: ending the claim leaves it waiting for a match.
            $this->queueNextRun($chain, $this->states->removeClaimedRequest($request->albumId, $claimId));

            return;
        }

        $outcome = $this->match($request->albumId);
        $left = $this->endClaim($request->albumId, $claimId, $outcome);
        if ($outcome?->kind === AutomaticMatchOutcomeKind::Unavailable) {
            $this->queueRun(new DrainAlbumMatchesMessage($chain, $message->outages + 1), self::outageDelay($message->outages));

            return;
        }

        $this->queueNextRun($chain, $left);
    }

    /** Matches the album without an admin; null when the matcher failed, which is logged. */
    private function match(Uuid $albumId): ?AutomaticMatchOutcome
    {
        try {
            return $this->matcher->match($albumId);
        } catch (\Throwable $e) {
            $this->logger->error('Automatic matching of album {album_id} failed; the album is matched again in an hour.', [
                'album_id' => $albumId->toString(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Ends the run's claim on the album with what the match found.
     *
     * @param AutomaticMatchOutcome|null $outcome null when the matcher failed
     *
     * @return AlbumMatchState|null the state the album is left in; null when its request is gone or the claim no longer held it
     */
    private function endClaim(Uuid $albumId, Uuid $claimId, ?AutomaticMatchOutcome $outcome): ?AlbumMatchState
    {
        return match ($outcome?->kind) {
            AutomaticMatchOutcomeKind::Matched, AutomaticMatchOutcomeKind::AlbumGone => $this->states->removeClaimedRequest($albumId, $claimId),
            AutomaticMatchOutcomeKind::NeedsReview => $this->states->markClaimedForReview(
                $albumId,
                $claimId,
                $outcome->reviewReason ?? throw new \LogicException('An album that needs review has a reason.'),
                $outcome->bestScore,
                $outcome->checkedSongCount ?? throw new \LogicException('An album that needs review has the song count it was checked against.'),
                $this->clock->now(),
            ),
            AutomaticMatchOutcomeKind::RetryLater => $this->states->postponeClaimedRequest(
                $albumId,
                $claimId,
                $this->after($outcome->retryAfterSeconds ?? throw new \LogicException('An album to retry later has a delay.')),
            ),
            AutomaticMatchOutcomeKind::Unavailable => $this->states->releaseClaim($albumId, $claimId),
            null => $this->states->postponeClaimedRequest($albumId, $claimId, $this->after(self::FAILURE_RETRY_SECONDS)),
        };
    }

    /** @param int<1, max> $seconds */
    private function after(int $seconds): DateTimeImmutable
    {
        return $this->clock->now()->add(new \DateInterval('PT' . $seconds . 'S'));
    }

    /**
     * Queues the chain's next run while any request waits: after the pause between albums, or when
     * the next request can be taken. With nothing left to wait for, the chain ends.
     *
     * A request made for the run's album during its claim queued no run: it relied on this one. So
     * when a newer chain replaced this run's chain meanwhile and the album waits for a match again,
     * the run queues one without a chain for the album's due time, unless another request falls
     * due at or before it and so brings a run that goes on to the album.
     *
     * @param AlbumMatchState|null $left the state this run left its album in, when it held one
     */
    private function queueNextRun(string $chain, ?AlbumMatchState $left = null): void
    {
        $nextDueAt = $this->states->nextRequestDueAt();
        if ($nextDueAt === null) {
            $this->chains->release($chain);

            return;
        }

        if ($this->queueRun(new DrainAlbumMatchesMessage($chain), $this->delayUntil($nextDueAt))) {
            return;
        }

        if ($left?->status === AlbumMatchStatus::Requested && !$this->states->hasRequestsDueBy($left->dueAt, $left->albumId)) {
            $this->bus->dispatch(new DrainAlbumMatchesMessage(), [new DelayStamp($this->delayUntil($left->dueAt))]);
        }
    }

    /**
     * Queues the chain's next run, unless a newer chain replaced it meanwhile.
     *
     * @return bool whether the run was queued
     */
    private function queueRun(DrainAlbumMatchesMessage $message, int $delayMs): bool
    {
        if ($message->chain === null || !$this->chains->hold($message->chain, intdiv($delayMs, 1000) + self::CHAIN_MARGIN_SECONDS)) {
            return false;
        }

        $this->bus->dispatch($message, [new DelayStamp($delayMs)]);

        return true;
    }

    /** The delay of a run for a request that can be taken at $dueAt: at least the pause between two albums. */
    private function delayUntil(DateTimeImmutable $dueAt): int
    {
        $untilDue = (int) ceil(((float) $dueAt->format('U.u') - (float) $this->clock->now()->format('U.u')) * 1000);

        return max(self::NEXT_RUN_DELAY_MS, $untilDue);
    }

    private static function outageDelay(int $outages): int
    {
        return self::OUTAGE_DELAYS_MS[min($outages, count(self::OUTAGE_DELAYS_MS) - 1)];
    }
}
