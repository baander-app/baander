<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\Command\RecoverAlbumMatchQueueCommand;
use App\Catalog\Application\CommandHandler\RecoverAlbumMatchQueueHandler;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\MessageHandler\DrainAlbumMatchesHandler;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchQueueChain;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcherInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The album match queue matches one album per run: it applies a confident match, marks the rest
 * for review, drops an import's request while automatic matching is off, waits out a MusicBrainz
 * outage, and keeps one run queued while requests wait. A run holds its album from claim to
 * outcome: a request made meanwhile is kept and takes effect when the run ends.
 */
final class DrainAlbumMatchesHandlerTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';

    private InMemoryAlbumMatchStates $states;
    private MockClock $clock;
    private AlbumMatchQueueChain $chains;
    /** @var list<array{int, int}> each queued run's outage count and its delay in milliseconds */
    private array $queued = [];
    /** @var list<string|null> the chain of each queued run */
    private array $queuedChains = [];
    /** @var list<string> the album IDs the matcher was asked to match */
    private array $matched = [];
    /** @var \Closure(Uuid): AutomaticMatchOutcome */
    private \Closure $outcome;
    private bool $automaticMatching = true;
    /** @var (\Closure(): void)|null runs when the handler reads the automatic matching switch, which it does once it holds an import's request */
    private ?\Closure $whenTheSwitchIsRead = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->states = new InMemoryAlbumMatchStates($this->clock);
        $this->chains = new AlbumMatchQueueChain(new ArrayAdapter(clock: $this->clock));
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::matched();
    }

    public function testAConfidentAlbumIsMatchedAndItsRequestIsGone(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));

        $this->drain();

        self::assertSame([$album->toString()], $this->matched);
        self::assertNull($this->states->stateOf($album));
        self::assertSame([], $this->queued, 'An empty queue queues no further run.');
    }

    /** The matcher reports an album applied without the cover the Cover Art Archive refused as matched. */
    public function testAnAlbumMatchedWithoutItsRefusedCoverLeavesTheQueueWhichGoesOnWithTheNextAlbumASecondLater(): void
    {
        $withoutCover = Uuid::v7();
        $next = Uuid::v7();
        $this->states->has(
            $this->request($withoutCover, AlbumMatchSource::Ingest, '-2 minutes'),
            $this->request($next, AlbumMatchSource::Ingest, '-1 minute'),
        );

        $this->drain();

        self::assertSame([$withoutCover->toString()], $this->matched);
        self::assertNull($this->states->stateOf($withoutCover));
        self::assertEquals([[0, 1_000]], $this->queued, 'The queue counts no outage and does not pause.');

        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0]));

        self::assertSame([$withoutCover->toString(), $next->toString()], $this->matched);
    }

    public function testAnAlbumWhoseBestCandidateHasAnotherTrackCountIsMarkedForReview(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::TrackCountDiffers, 96, 26);

        $this->drain();

        $state = $this->states->stateOf($album);
        self::assertNotNull($state);
        self::assertSame(AlbumMatchStatus::NeedsReview, $state->status);
        self::assertSame(AlbumMatchSource::Ingest, $state->source);
        self::assertSame(AlbumMatchReviewReason::TrackCountDiffers, $state->reason);
        self::assertSame(96, $state->bestScore);
        self::assertSame(26, $state->checkedSongCount);
        self::assertEquals(new DateTimeImmutable(self::NOW), $state->checkedAt);
        self::assertSame([], $this->queued);
    }

    public function testARequestMadeWhileTheAlbumIsMatchedKeepsItWaitingInsteadOfMarkingItForReview(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        $this->outcome = function (Uuid $albumId): AutomaticMatchOutcome {
            // The album's second disc lands while MusicBrainz is asked about the first.
            $this->states->request($albumId, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +5 minutes'));

            return AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::TrackCountDiffers, 96, 13);
        };

        $this->drain();

        $state = $this->states->stateOf($album);
        self::assertNotNull($state);
        self::assertSame(AlbumMatchStatus::Requested, $state->status);
        self::assertEquals(new DateTimeImmutable(self::NOW . ' +5 minutes'), $state->dueAt);
        self::assertEquals([[0, 300_000]], $this->queued);
    }

    public function testAnAlbumAnAdminRequestsWhileARunMatchesItIsSearchedOnceAndThenWaitsFromThatRequest(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        $chain = $this->chains->start(60);
        $this->outcome = function (Uuid $albumId) use ($chain): AutomaticMatchOutcome {
            // Two seconds into the search an admin syncs the album, and the queue's run is delivered again.
            $this->clock->sleep(2);
            self::assertFalse($this->states->request($albumId, AlbumMatchSource::Admin, $this->clock->now()), 'The run that holds the album brings the next run.');
            $this->drain(new DrainAlbumMatchesMessage($chain));

            return AutomaticMatchOutcome::matched();
        };

        $this->drain(new DrainAlbumMatchesMessage($chain));

        self::assertSame([$album->toString()], $this->matched, 'No second run took the album, so MusicBrainz was searched once.');
        self::assertEquals($this->request($album, AlbumMatchSource::Admin, '+2 seconds'), $this->states->stateOf($album));
        self::assertEquals(
            [[0, 898_000], [0, 1_000]],
            $this->queued,
            'The run that found the album held waits for its claim to lapse; the run that matched it comes back for the request.',
        );
        self::assertSame([$chain, $chain], $this->queuedChains);
    }

    /**
     * The newer chain counted the held album from its claim's expiry, so its next run would come
     * late for the album: the run queues one without a chain, which takes the newer chain's place.
     * A request that falls due first already brings a run that goes on to the album. After an
     * outage the run queues nothing: the album keeps its earlier due time, and the newer chain
     * keeps a run on its way while the request waits.
     *
     * @param ?string               $requestedFor the due time of a request made for the album during the match
     * @param ?string               $otherDue     the due time of another album's request
     * @param string                $dueAfter     the due time the album waits with once the run ends
     * @param list<array{int, int}> $queued
     */
    #[DataProvider('outcomesThatLeaveTheAlbumWaiting')]
    public function testARunWhoseChainWasReplacedQueuesARunWithoutAChainForTheAlbumItLeftWaitingUnlessAnotherRequestFallsDueFirstOrAnOutageStoppedTheMatch(
        ?string $requestedFor,
        AutomaticMatchOutcome $outcome,
        ?string $otherDue,
        string $dueAfter,
        array $queued,
    ): void {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        if ($otherDue !== null) {
            $this->states->has($this->request(Uuid::v7(), AlbumMatchSource::Ingest, $otherDue));
        }
        $this->outcome = function (Uuid $albumId) use ($requestedFor, $outcome): AutomaticMatchOutcome {
            if ($requestedFor !== null) {
                $this->states->request($albumId, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' ' . $requestedFor));
            }
            // A request for another album started a run of its own, and with it a new chain.
            $this->chains->start(60);

            return $outcome;
        };

        $this->drain();

        self::assertEquals($this->request($album, AlbumMatchSource::Ingest, $dueAfter), $this->states->stateOf($album), 'The album waits for a match, with no review.');
        self::assertEquals(
            min(new DateTimeImmutable(self::NOW . ' ' . $dueAfter), new DateTimeImmutable(self::NOW . ' ' . ($otherDue ?? $dueAfter))),
            $this->states->nextRequestDueAt(),
            'No claim holds the album any more: the queue counts it from its due time.',
        );
        self::assertEquals($queued, $this->queued);
        self::assertSame(array_fill(0, count($queued), null), $this->queuedChains);
    }

    /** @return iterable<string, array{?string, AutomaticMatchOutcome, ?string, string, list<array{int, int}>}> */
    public static function outcomesThatLeaveTheAlbumWaiting(): iterable
    {
        $review = AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::TrackCountDiffers, 96, 13);

        yield 'a request made during the match' => ['+5 minutes', AutomaticMatchOutcome::matched(), null, '+5 minutes', [[0, 300_000]]];
        yield 'an album to retry later' => [null, AutomaticMatchOutcome::retryLater(900), null, '+15 minutes', [[0, 900_000]]];
        yield 'a request made during the match, behind another request' => ['+5 minutes', AutomaticMatchOutcome::matched(), '+1 minute', '+5 minutes', []];
        yield 'an album to retry later, behind another request' => [null, AutomaticMatchOutcome::retryLater(900), '+1 minute', '+15 minutes', []];
        yield 'a review with a request made during the match' => ['+5 minutes', $review, null, '+5 minutes', [[0, 300_000]]];
        yield 'a review with a request made during the match, behind another request' => ['+5 minutes', $review, '+1 minute', '+5 minutes', []];
        yield 'an outage with a request made during the match' => ['+5 minutes', AutomaticMatchOutcome::unavailable(), null, '-1 minute', []];
    }

    /**
     * A failing matcher leaves the album due an hour later, which the newer chain does not know
     * of: the run queues a run without a chain for that time, unless another request falls due
     * first and brings a run that goes on to the album.
     *
     * @param ?string               $otherDue the due time of another album's request
     * @param list<array{int, int}> $queued
     */
    #[DataProvider('failedMatchesOfAReplacedChain')]
    public function testARunWhoseChainWasReplacedWhileItsMatcherFailedQueuesARunWithoutAChainForTheAlbumAnHourLaterUnlessAnotherRequestFallsDueFirst(
        ?string $otherDue,
        array $queued,
    ): void {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        if ($otherDue !== null) {
            $this->states->has($this->request(Uuid::v7(), AlbumMatchSource::Ingest, $otherDue));
        }
        $this->outcome = function (): AutomaticMatchOutcome {
            // A request for another album started a run of its own, and with it a new chain.
            $this->chains->start(60);

            throw new \RuntimeException('A bug in the apply.');
        };

        $this->drain();

        self::assertEquals($this->request($album, AlbumMatchSource::Ingest, '+1 hour'), $this->states->stateOf($album));
        self::assertEquals($queued, $this->queued);
        self::assertSame(array_fill(0, count($queued), null), $this->queuedChains);
    }

    /** @return iterable<string, array{?string, list<array{int, int}>}> */
    public static function failedMatchesOfAReplacedChain(): iterable
    {
        yield 'a failed match' => [null, [[0, 3_600_000]]];
        yield 'a failed match, behind another request' => ['+1 minute', []];
    }

    public function testAnImportRequestIsDroppedWhileAutomaticMatchingIsOffAndAnAdminRequestStillMatches(): void
    {
        $this->automaticMatching = false;
        $imported = Uuid::v7();
        $later = Uuid::v7();
        $admin = Uuid::v7();
        $this->states->has(
            $this->request($imported, AlbumMatchSource::Ingest, '-2 minutes'),
            $this->request($later, AlbumMatchSource::Ingest, '+4 minutes'),
            $this->request($admin, AlbumMatchSource::Admin, '-1 minute'),
        );

        $this->drain();

        self::assertSame([], $this->matched);
        self::assertNull($this->states->stateOf($imported));
        self::assertNull($this->states->stateOf($later));
        self::assertEquals([[0, 1_000]], $this->queued);

        $this->drain();

        self::assertSame([$admin->toString()], $this->matched);
        self::assertSame([], $this->states->states);
    }

    public function testAnImportRequestAnAdminRequestedDuringItsClaimOutlivesTheRemovalWithoutAClaim(): void
    {
        $this->automaticMatching = false;
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        // An admin syncs the album after the run claimed its request and before the run removes import requests.
        $this->whenTheSwitchIsRead = function () use ($album): void {
            $this->states->request($album, AlbumMatchSource::Admin, $this->clock->now());
        };

        $this->drain();

        self::assertSame([], $this->matched);
        self::assertEquals($this->request($album, AlbumMatchSource::Admin, '+0 minutes'), $this->states->stateOf($album));
        self::assertEquals([[0, 1_000]], $this->queued);

        $this->whenTheSwitchIsRead = null;
        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0]));

        self::assertSame([$album->toString()], $this->matched, 'The request holds no claim, so the next run matches it.');
    }

    /**
     * The newer chain counted the held album from its claim's expiry, so the run whose chain was
     * replaced queues a run without a chain for the admin request.
     */
    public function testAnAdminRequestMadeDuringTheClaimOfARunWhoseChainWasReplacedOutlivesTheRemovalWithARunWithoutAChainASecondAhead(): void
    {
        $this->automaticMatching = false;
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        // An admin syncs the album after the run claimed its request and before the run removes
        // import requests, and the admin's request for another album started a run with a new chain.
        $this->whenTheSwitchIsRead = function () use ($album): void {
            $this->states->request($album, AlbumMatchSource::Admin, $this->clock->now());
            $this->chains->start(60);
        };

        $this->drain();

        self::assertSame([], $this->matched);
        self::assertEquals($this->request($album, AlbumMatchSource::Admin, '+0 minutes'), $this->states->stateOf($album));
        self::assertEquals([[0, 1_000]], $this->queued);
        self::assertSame([null], $this->queuedChains);

        $this->whenTheSwitchIsRead = null;
        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0]));

        self::assertSame([$album->toString()], $this->matched, 'The run without a chain matches the admin request.');
    }

    public function testAMusicBrainzOutageKeepsTheRequestWritesNoReviewAndPausesTheQueue(): void
    {
        $album = Uuid::v7();
        $request = $this->request($album, AlbumMatchSource::Ingest, '-1 minute');
        $this->states->has($request);
        $this->outcome = function (Uuid $albumId): AutomaticMatchOutcome {
            // The album's second disc lands while MusicBrainz does not answer.
            $this->states->request($albumId, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +5 minutes'));

            return AutomaticMatchOutcome::unavailable();
        };

        $this->drain();

        self::assertEquals($request, $this->states->stateOf($album), 'The album keeps the earlier due time.');
        self::assertEquals([[1, 300_000]], $this->queued);

        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0], 1));

        self::assertSame([$album->toString(), $album->toString()], $this->matched, 'The request holds no claim, so the run after the pause takes it.');
    }

    public function testOutagesInARowPauseTheQueueForFifteenMinutesThenAnHourThenHourly(): void
    {
        $this->states->has($this->request(Uuid::v7(), AlbumMatchSource::Admin, '-1 minute'));
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::unavailable();

        foreach ([1, 2, 3, 7] as $outages) {
            $this->drain(new DrainAlbumMatchesMessage(outages: $outages));
        }

        self::assertEquals([
            [2, 900_000],
            [3, 3_600_000],
            [4, 3_600_000],
            [8, 3_600_000],
        ], $this->queued);
    }

    public function testAnAnswerAfterAnOutageMatchesAndQueuesTheNextAlbumAtTheUsualPace(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->states->has(
            $this->request($first, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($second, AlbumMatchSource::Admin, '-1 minute'),
        );

        $this->drain(new DrainAlbumMatchesMessage(outages: 3));

        self::assertSame([$first->toString()], $this->matched);
        self::assertEquals([[0, 1_000]], $this->queued);
    }

    public function testTheNextRunWaitsForARequestThatIsNotDueYet(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '+90 seconds'));

        $this->drain();

        self::assertSame([], $this->matched);
        self::assertEquals([[0, 90_000]], $this->queued);
        self::assertEquals(new DateTimeImmutable(self::NOW . ' +90 seconds'), $this->states->stateOf($album)?->dueAt);
    }

    public function testAnAlbumDeletedSinceItsRequestIsForgotten(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Admin, '-1 minute'));
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::albumGone();

        $this->drain();

        self::assertSame([], $this->states->states);
        self::assertSame([], $this->queued);
    }

    public function testRunsAtOnceNeverTakeTheSameAlbumAndWaitForAHeldAlbumsClaimToLapse(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $third = Uuid::v7();
        $this->states->has(
            $this->request($first, AlbumMatchSource::Admin, '-3 minutes'),
            $this->request($second, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($third, AlbumMatchSource::Admin, '-1 minute'),
        );
        $started = false;
        $this->outcome = function () use (&$started): AutomaticMatchOutcome {
            if (!$started) {
                $started = true;
                // Three more runs start while the first waits for MusicBrainz.
                $this->drain();
                $this->drain();
                $this->drain();
            }

            return AutomaticMatchOutcome::matched();
        };

        $this->drain();

        self::assertSame([$first->toString(), $second->toString(), $third->toString()], $this->matched, 'Each album is matched once; the fourth run finds the last one held.');
        self::assertSame([], $this->states->states);
        self::assertEquals(
            [[0, 1_000], [0, 900_000], [0, 900_000]],
            $this->queued,
            'While another request is due the next run comes a second later; with only the held album left, when its claim lapses.',
        );
    }

    public function testAFailingMatcherLeavesItsAlbumDueAnHourLaterAndTheQueueGoesOn(): void
    {
        $failing = Uuid::v7();
        $next = Uuid::v7();
        $this->states->has(
            $this->request($failing, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($next, AlbumMatchSource::Admin, '-1 minute'),
        );
        $this->outcome = static fn (Uuid $albumId): AutomaticMatchOutcome => $albumId->equals($failing)
            ? throw new \RuntimeException('A bug in the apply.')
            : AutomaticMatchOutcome::matched();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Automatic matching of album {album_id} failed'),
            self::callback(static fn (array $context): bool => $context['album_id'] === $failing->toString() && $context['exception'] instanceof \RuntimeException),
        );

        $this->drain(logger: $logger);

        self::assertEquals($this->request($failing, AlbumMatchSource::Admin, '+1 hour'), $this->states->stateOf($failing));
        self::assertEquals([[0, 1_000]], $this->queued);

        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0]));

        self::assertSame([$failing->toString(), $next->toString()], $this->matched);
        self::assertEquals([[0, 1_000], [0, 3_600_000]], $this->queued, 'The failing album is given back, not held: the queue waits for its due time, not for a claim to lapse.');
    }

    public function testAnAlbumToRetryLaterWaitsItsDelayWhileTheQueueGoesOnWithTheNextAlbum(): void
    {
        $conflicted = Uuid::v7();
        $next = Uuid::v7();
        $this->states->has(
            $this->request($conflicted, AlbumMatchSource::Ingest, '-2 minutes'),
            $this->request($next, AlbumMatchSource::Ingest, '-1 minute'),
        );
        $this->outcome = static fn (Uuid $albumId): AutomaticMatchOutcome => $albumId->equals($conflicted)
            ? AutomaticMatchOutcome::retryLater(900)
            : AutomaticMatchOutcome::matched();

        $this->drain();

        self::assertEquals(
            $this->request($conflicted, AlbumMatchSource::Ingest, '+15 minutes'),
            $this->states->stateOf($conflicted),
            'Only this album waits, with no review mark.',
        );
        self::assertEquals([[0, 1_000]], $this->queued, 'The queue does not pause.');

        $this->drain(new DrainAlbumMatchesMessage($this->queuedChains[0]));

        self::assertSame([$conflicted->toString(), $next->toString()], $this->matched);
        self::assertNull($this->states->stateOf($next));
    }

    public function testARunWithoutAChainStartsOneWhichItsNextRunsCarryAndWhichEndsWithTheQueue(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->states->has(
            $this->request($first, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($second, AlbumMatchSource::Admin, '-1 minute'),
        );

        $this->drain();
        $chain = $this->queuedChains[0];
        self::assertNotNull($chain);
        self::assertTrue($this->chains->isRunning());

        $this->drain(new DrainAlbumMatchesMessage($chain));

        self::assertSame([$first->toString(), $second->toString()], $this->matched);
        self::assertCount(1, $this->queued, 'The queue is empty after the second album.');
        self::assertFalse($this->chains->isRunning(), 'The chain ended with the queue.');
    }

    public function testARunOfAReplacedChainEndsWithoutMatchingOrQueuingAnything(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->states->has(
            $this->request($first, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($second, AlbumMatchSource::Admin, '-1 minute'),
        );
        $this->drain();
        $replaced = $this->queuedChains[0];
        self::assertNotNull($replaced);
        // The queue's recovery started a new chain while this one's next run was late.
        $this->chains->start(60);

        $this->drain(new DrainAlbumMatchesMessage($replaced));

        self::assertSame([$first->toString()], $this->matched);
        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($second)?->status);
        self::assertCount(1, $this->queued);
    }

    public function testARunThatDiedHoldsItsChainAndItsAlbumForFifteenMinutesAndTheRecoveryThenStartsTheQueue(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '-1 minute'));
        // The run dies once it holds the album: its read of the switch fails, and it queues no successor.
        $this->whenTheSwitchIsRead = static fn () => throw new \RuntimeException('The database went away.');

        try {
            $this->drain();
            self::fail('The run died.');
        } catch (\RuntimeException $death) {
            self::assertSame('The database went away.', $death->getMessage());
        }
        self::assertSame([], $this->queued);

        $this->clock->sleep(899);
        self::assertTrue($this->chains->isRunning(), 'The dead run still holds its chain.');
        self::assertSame('no match due', $this->recover(), 'The dead run still holds its album.');

        $this->clock->sleep(1);
        self::assertFalse($this->chains->isRunning());
        self::assertSame('queue started', $this->recover());
    }

    /** Runs the queue's scheduled recovery and returns what it did. */
    private function recover(): string
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new RecoverAlbumMatchQueueHandler($this->states, $this->chains, $bus, $this->clock, new NullLogger())(
            new RecoverAlbumMatchQueueCommand(),
        );
    }

    private function drain(DrainAlbumMatchesMessage $message = new DrainAlbumMatchesMessage(), ?LoggerInterface $logger = null): void
    {
        $matcher = new class ($this) implements AutomaticAlbumMatcherInterface {
            public function __construct(private readonly DrainAlbumMatchesHandlerTest $test)
            {
            }

            public function match(Uuid $albumId): AutomaticMatchOutcome
            {
                return $this->test->outcomeFor($albumId);
            }
        };
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(function (string $key): bool {
            ($this->whenTheSwitchIsRead ?? static fn () => null)();

            return $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching;
        });
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps): Envelope {
            self::assertInstanceOf(DrainAlbumMatchesMessage::class, $message);
            $delay = array_find($stamps, static fn (object $stamp): bool => $stamp instanceof DelayStamp);
            self::assertInstanceOf(DelayStamp::class, $delay);
            $this->queued[] = [$message->outages, $delay->getDelay()];
            $this->queuedChains[] = $message->chain;

            return new Envelope($message, $stamps);
        });

        new DrainAlbumMatchesHandler($this->states, $matcher, $this->chains, $settings, $bus, $this->clock, $logger ?? new NullLogger())($message);
    }

    /** @internal called by the fake matcher */
    public function outcomeFor(Uuid $albumId): AutomaticMatchOutcome
    {
        $this->matched[] = $albumId->toString();

        return ($this->outcome)($albumId);
    }

    private function request(Uuid $albumId, AlbumMatchSource $source, string $due): AlbumMatchState
    {
        return new AlbumMatchState($albumId, AlbumMatchStatus::Requested, $source, new DateTimeImmutable(self::NOW . ' ' . $due));
    }
}
