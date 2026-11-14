<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\MessageHandler\DrainAlbumMatchesHandler;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The album match queue matches one album per run: it applies a confident match, marks the rest
 * for review, drops an import's request while automatic matching is off, waits out a MusicBrainz
 * outage, and keeps one run queued while requests wait.
 */
final class DrainAlbumMatchesHandlerTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';

    private InMemoryAlbumMatchStates $states;
    private MockClock $clock;
    /** @var list<array{DrainAlbumMatchesMessage, int}> each queued run and its delay in milliseconds */
    private array $queued = [];
    /** @var list<string> the album IDs the matcher was asked to match */
    private array $matched = [];
    /** @var \Closure(Uuid): AutomaticMatchOutcome */
    private \Closure $outcome;
    private bool $automaticMatching = true;

    protected function setUp(): void
    {
        $this->states = new InMemoryAlbumMatchStates();
        $this->clock = new MockClock(self::NOW);
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
        self::assertEquals([[new DrainAlbumMatchesMessage(), 300_000]], $this->queued);
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
        self::assertEquals([[new DrainAlbumMatchesMessage(), 1_000]], $this->queued);

        $this->drain();

        self::assertSame([$admin->toString()], $this->matched);
        self::assertSame([], $this->states->states);
    }

    public function testAMusicBrainzOutageKeepsTheRequestWritesNoReviewAndPausesTheQueue(): void
    {
        $album = Uuid::v7();
        $request = $this->request($album, AlbumMatchSource::Ingest, '-1 minute');
        $this->states->has($request);
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::unavailable();

        $this->drain();

        self::assertEquals($request, $this->states->stateOf($album));
        self::assertEquals([[new DrainAlbumMatchesMessage(1), 300_000]], $this->queued);
    }

    public function testOutagesInARowPauseTheQueueForFifteenMinutesThenAnHourThenHourly(): void
    {
        $this->states->has($this->request(Uuid::v7(), AlbumMatchSource::Admin, '-1 minute'));
        $this->outcome = static fn (): AutomaticMatchOutcome => AutomaticMatchOutcome::unavailable();

        foreach ([1, 2, 3, 7] as $outages) {
            $this->drain(new DrainAlbumMatchesMessage($outages));
        }

        self::assertEquals([
            [new DrainAlbumMatchesMessage(2), 900_000],
            [new DrainAlbumMatchesMessage(3), 3_600_000],
            [new DrainAlbumMatchesMessage(4), 3_600_000],
            [new DrainAlbumMatchesMessage(8), 3_600_000],
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

        $this->drain(new DrainAlbumMatchesMessage(3));

        self::assertSame([$first->toString()], $this->matched);
        self::assertEquals([[new DrainAlbumMatchesMessage(), 1_000]], $this->queued);
    }

    public function testTheNextRunWaitsForARequestThatIsNotDueYet(): void
    {
        $album = Uuid::v7();
        $this->states->has($this->request($album, AlbumMatchSource::Ingest, '+90 seconds'));

        $this->drain();

        self::assertSame([], $this->matched);
        self::assertEquals([[new DrainAlbumMatchesMessage(), 90_000]], $this->queued);
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

    public function testTwoRunsAtOnceNeverTakeTheSameAlbum(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->states->has(
            $this->request($first, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($second, AlbumMatchSource::Admin, '-1 minute'),
        );
        $started = false;
        $this->outcome = function () use (&$started): AutomaticMatchOutcome {
            if (!$started) {
                $started = true;
                // A second run starts while the first waits for MusicBrainz.
                $this->drain();
                $this->drain();
            }

            return AutomaticMatchOutcome::matched();
        };

        $this->drain();

        self::assertSame([$first->toString(), $second->toString()], $this->matched, 'Each album is matched once; the third run finds both taken.');
        self::assertSame([], $this->states->states);
    }

    public function testAFailingMatcherLeavesItsAlbumClaimedForAnHourAndTheQueueGoesOn(): void
    {
        $failing = Uuid::v7();
        $next = Uuid::v7();
        $this->states->has(
            $this->request($failing, AlbumMatchSource::Admin, '-2 minutes'),
            $this->request($next, AlbumMatchSource::Admin, '-1 minute'),
        );
        $this->outcome = static fn (): AutomaticMatchOutcome => throw new \RuntimeException('A bug in the apply.');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Automatic matching of album {album_id} failed'),
            self::callback(static fn (array $context): bool => $context['album_id'] === $failing->toString() && $context['exception'] instanceof \RuntimeException),
        );

        $this->drain(logger: $logger);

        self::assertEquals(new DateTimeImmutable(self::NOW . ' +1 hour'), $this->states->stateOf($failing)?->dueAt);
        self::assertEquals([[new DrainAlbumMatchesMessage(), 1_000]], $this->queued);
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
        $settings->method('get')->willReturnCallback(fn (string $key): bool => $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps): Envelope {
            self::assertInstanceOf(DrainAlbumMatchesMessage::class, $message);
            $delay = array_find($stamps, static fn (object $stamp): bool => $stamp instanceof DelayStamp);
            self::assertInstanceOf(DelayStamp::class, $delay);
            $this->queued[] = [$message, $delay->getDelay()];

            return new Envelope($message, $stamps);
        });

        new DrainAlbumMatchesHandler($this->states, $matcher, $settings, $bus, $this->clock, $logger ?? new NullLogger())($message);
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
