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
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
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
 * Each run claims the request that has been due longest and matches its album without an admin.
 * An import's request is removed instead while automatic matching is off; admin requests are
 * matched whatever the switch says. A confident album is matched, any other album is marked for
 * review. An album whose apply met changed rows or a deadlock, or about which MusicBrainz refused a
 * request, is given back due later while the queue goes on with the next album. A MusicBrainz
 * outage gives the request back and pauses the queue for 5, 15 and 60 minutes, then hourly. While
 * requests wait, the run queues the next one: a second later, or when the next request falls due.
 * A matcher failure is logged and leaves the request claimed until the claim lapses, so one album
 * cannot stop the queue.
 *
 * The runs form one chain (AlbumMatchQueueChain). A run without a chain starts one in place of any
 * other; a run of a chain that was replaced ends at once, without matching or queuing a successor.
 */
final readonly class DrainAlbumMatchesHandler
{
    /** The pause between two albums, which lets other queued work run in between. */
    private const int NEXT_RUN_DELAY_MS = 1_000;

    /** The pauses after one, two and three or more outages in a row. */
    private const array OUTAGE_DELAYS_MS = [300_000, 900_000, 3_600_000];

    /** How long a run holds the request it took; a request whose run died falls due again after it. */
    private const int CLAIM_SECONDS = 3_600;

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

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(DrainAlbumMatchesMessage $message): void
    {
        $chain = $message->chain;
        if ($chain === null) {
            $chain = $this->chains->start(self::CLAIM_SECONDS);
        } elseif (!$this->chains->hold($chain, self::CLAIM_SECONDS)) {
            // A newer chain works the queue.
            return;
        }

        $now = $this->clock->now();
        $claimedUntil = $now->add(new \DateInterval('PT' . self::CLAIM_SECONDS . 'S'));
        $request = $this->states->claimOldestDueRequest($now, $claimedUntil);

        if ($request?->source === AlbumMatchSource::Ingest && $this->settings->get(MetadataSettingDefinitions::AUTO_SYNC) !== true) {
            $this->states->removeIngestRequests();
        } elseif ($request !== null && $this->match($request, $claimedUntil)?->kind === AutomaticMatchOutcomeKind::Unavailable) {
            $this->states->releaseClaim($request->albumId, $claimedUntil, $request->dueAt);
            $this->queueRun(new DrainAlbumMatchesMessage($chain, $message->outages + 1), self::outageDelay($message->outages));

            return;
        }

        $this->queueNextRun($chain);
    }

    /** Matches the claimed request's album and records the outcome; null when the matcher failed. */
    private function match(AlbumMatchState $request, DateTimeImmutable $claimedUntil): ?AutomaticMatchOutcome
    {
        try {
            $outcome = $this->matcher->match($request->albumId);
        } catch (\Throwable $e) {
            $this->logger->error('Automatic matching of album {album_id} failed; the album is matched again in an hour.', [
                'album_id' => $request->albumId->toString(),
                'exception' => $e,
            ]);

            return null;
        }

        match ($outcome->kind) {
            AutomaticMatchOutcomeKind::Matched, AutomaticMatchOutcomeKind::AlbumGone => $this->states->removeClaimedRequest($request->albumId, $claimedUntil),
            AutomaticMatchOutcomeKind::NeedsReview => $this->markForReview($request, $claimedUntil, $outcome),
            AutomaticMatchOutcomeKind::RetryLater => $this->states->releaseClaim($request->albumId, $claimedUntil, $this->retryAt($outcome)),
            AutomaticMatchOutcomeKind::Unavailable => null,
        };

        return $outcome;
    }

    private function markForReview(AlbumMatchState $request, DateTimeImmutable $claimedUntil, AutomaticMatchOutcome $outcome): void
    {
        $this->states->markClaimedForReview(
            $request->albumId,
            $claimedUntil,
            $outcome->reviewReason ?? throw new \LogicException('An album that needs review has a reason.'),
            $outcome->bestScore,
            $outcome->checkedSongCount ?? throw new \LogicException('An album that needs review has the song count it was checked against.'),
            $this->clock->now(),
        );
    }

    private function retryAt(AutomaticMatchOutcome $outcome): DateTimeImmutable
    {
        $seconds = $outcome->retryAfterSeconds ?? throw new \LogicException('An album to retry later has a delay.');

        return $this->clock->now()->add(new \DateInterval('PT' . $seconds . 'S'));
    }

    /**
     * Queues the chain's next run while any request waits: after the pause between albums, or when
     * the next request falls due. With nothing left to wait for, the chain ends.
     */
    private function queueNextRun(string $chain): void
    {
        $nextDueAt = $this->states->nextRequestDueAt();
        if ($nextDueAt === null) {
            $this->chains->release($chain);

            return;
        }

        $untilDue = (int) ceil(((float) $nextDueAt->format('U.u') - (float) $this->clock->now()->format('U.u')) * 1000);
        $this->queueRun(new DrainAlbumMatchesMessage($chain), max(self::NEXT_RUN_DELAY_MS, $untilDue));
    }

    /** Queues the chain's next run, unless a newer chain replaced it meanwhile. */
    private function queueRun(DrainAlbumMatchesMessage $message, int $delayMs): void
    {
        if ($message->chain !== null && $this->chains->hold($message->chain, intdiv($delayMs, 1000) + self::CHAIN_MARGIN_SECONDS)) {
            $this->bus->dispatch($message, [new DelayStamp($delayMs)]);
        }
    }

    private static function outageDelay(int $outages): int
    {
        return self::OUTAGE_DELAYS_MS[min($outages, count(self::OUTAGE_DELAYS_MS) - 1)];
    }
}
