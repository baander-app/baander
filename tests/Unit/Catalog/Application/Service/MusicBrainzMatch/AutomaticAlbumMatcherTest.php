<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyOutcome;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumReleaseSearch;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcher;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcomeKind;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchConfidence;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPair;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The album match queue's matcher: an unmatched album is searched, decided by MatchConfidence and
 * applied in automatic mode when one candidate is confident; a matched album pairs only its new
 * songs against its stored release. MusicBrainz is a fake and the apply a stubbed bus.
 */
final class AutomaticAlbumMatcherTest extends TestCase
{
    use MatchQueryFixtures;

    private const string SEARCH = self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST;

    private const string RELEASE_TEXT_TITLE = 'Ten Thousand Fists [Reprise Records,9362-49433-2,EU]';
    private const string SHORTENED_TITLE = 'Ten Thousand Fists';
    private const string DISTURBED = 'Disturbed';
    private const string RELEASE_TEXT_SEARCH = self::RELEASE_TEXT_TITLE . '|' . self::DISTURBED;
    private const string SHORTENED_SEARCH = self::SHORTENED_TITLE . '|' . self::DISTURBED;

    /** @var list<ApplyMatchCommand> */
    private array $applied = [];
    private ?\Closure $applyResult = null;

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    public function testAConfidentAlbumIsAppliedInAutomaticModeAndMatched(): void
    {
        $album = $this->longBed();
        $release = self::release(self::releaseId(1));
        $this->found($release);

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        self::assertCount(1, $this->applied);
        $command = $this->applied[0];
        self::assertTrue($command->isAutomatic());
        self::assertTrue($command->albumId->equals($album->getId()));
        self::assertSame($release->id, $command->candidateId);
        self::assertNull($command->releaseIdSeen);
        self::assertCount(27, $command->candidate->pairs->pairs);
    }

    public function testTwinEditionsAreAppliedAsOneCandidateRecordingTheEarlierEdition(): void
    {
        $album = $this->longBed();
        $explicit = self::release(self::releaseId(1));
        $clean = self::release(self::releaseId(2), changes: ['1-3' => ['recordingId' => '5ec0bd00-0000-4000-8000-c1ea00000003']], date: '2024-08-23');
        $this->found($clean, $explicit);

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        $candidate = $this->applied[0]->candidate;
        self::assertSame($explicit->id, $candidate->release->id);
        self::assertSame([$explicit->id, $clean->id], array_column($candidate->editions, 'id'));
        $third = array_find($candidate->pairs->pairs, static fn (TrackPair $pair): bool => $pair->release->key() === '1-3');
        self::assertNotNull($third);
        self::assertNull($candidate->recordingId($third->release), 'The editions disagree on this recording.');
    }

    public function testABestCandidateWithAnotherTrackCountLeavesTheAlbumForReviewUnapplied(): void
    {
        $album = $this->longBed();
        $this->found(self::release(self::releaseId(1), [18, 10]));

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::NeedsReview, $outcome->kind);
        self::assertSame(AlbumMatchReviewReason::TrackCountDiffers, $outcome->reviewReason);
        self::assertNotNull($outcome->bestScore);
        self::assertSame(27, $outcome->checkedSongCount);
        self::assertSame([], $this->applied);
    }

    public function testAPoorlyFittingReleaseLeavesTheAlbumForReviewWithALowScore(): void
    {
        $album = $this->longBed();
        $this->found(self::release(self::releaseId(1), title: 'Nothing Alike'));

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::NeedsReview, $outcome->kind);
        self::assertSame(AlbumMatchReviewReason::LowScore, $outcome->reviewReason);
        self::assertLessThan(MatchConfidence::MINIMUM_SCORE, $outcome->bestScore);
        self::assertSame([], $this->applied);
    }

    public function testAnAlbumMusicBrainzFindsNothingForIsLeftForReviewWithoutCandidates(): void
    {
        $album = $this->longBed();

        $outcome = $this->match($album);

        self::assertEquals(AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::NoCandidates, null, 27), $outcome);
        self::assertSame([self::SEARCH], $this->searches());
    }

    public function testAnAlbumFoundByItsShortenedTitleIsScoredAgainstThatTitleAndKeepsItsStoredOne(): void
    {
        $release = self::tenThousandFistsRelease();
        $album = $this->tenThousandFists($release);
        $this->musicBrainz->releaseSearches[self::SHORTENED_SEARCH] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;

        $outcome = $this->match($album);

        self::assertSame([self::RELEASE_TEXT_SEARCH, self::SHORTENED_SEARCH], $this->searches());
        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        self::assertCount(1, $this->applied);
        self::assertSame($release->id, $this->applied[0]->candidateId);
        self::assertSame(100, $this->applied[0]->candidate->score, 'The candidate is scored against the title that found it.');
        self::assertSame(self::RELEASE_TEXT_TITLE, $album->getTitle(), 'The stored title stays.');
    }

    public function testAnAlbumNeitherSearchFindsAReleaseForIsLeftForReviewWithoutCandidates(): void
    {
        $album = self::tenThousandFistsRelease() |> $this->tenThousandFists(...);

        $outcome = $this->match($album);

        self::assertSame([self::RELEASE_TEXT_SEARCH, self::SHORTENED_SEARCH], $this->searches());
        self::assertEquals(AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::NoCandidates, null, 14), $outcome);
        self::assertSame([], $this->applied);
    }

    public function testASecondSearchMusicBrainzRefusesRetriesThisAlbumAfterSixHours(): void
    {
        $album = self::tenThousandFistsRelease() |> $this->tenThousandFists(...);
        $this->musicBrainz->releaseSearches[self::SHORTENED_SEARCH] = new MusicBrainzRejected(400);

        self::assertEquals(AutomaticMatchOutcome::retryLater(21_600), $this->match($album));
        self::assertSame([], $this->applied);
    }

    public function testASearchMusicBrainzDoesNotAnswerIsUnavailableAndAppliesNothing(): void
    {
        $album = $this->longBed();
        $this->musicBrainz->releaseSearches[self::SEARCH] = new MusicBrainzUnavailable('HTTP 503');

        self::assertSame(AutomaticMatchOutcomeKind::Unavailable, $this->match($album)->kind);
        self::assertSame([], $this->applied);
    }

    public function testASearchMusicBrainzRefusesRetriesThisAlbumAfterSixHours(): void
    {
        $album = $this->longBed();
        $this->musicBrainz->releaseSearches[self::SEARCH] = new MusicBrainzRejected(400);

        self::assertEquals(AutomaticMatchOutcome::retryLater(21_600), $this->match($album));
        self::assertSame([], $this->applied);
    }

    public function testALookupMusicBrainzDoesNotAnswerIsUnavailable(): void
    {
        $album = $this->longBed();
        $release = self::release(self::releaseId(1));
        $this->found($release);
        $this->musicBrainz->releases[$release->id] = new MusicBrainzUnavailable('HTTP 503');

        self::assertSame(AutomaticMatchOutcomeKind::Unavailable, $this->match($album)->kind);
        self::assertSame([], $this->applied);
    }

    public function testAMatchedAlbumPairsOnlyTheSongsNoMatchComparedAgainstItsStoredRelease(): void
    {
        $release = self::release(self::releaseId(1));
        $album = $this->longBed(missing: ['2-9']);
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new DateTimeImmutable('-1 hour'));
        $this->checkAll();
        $this->addSongsOf($album, $release, missing: [
            ...array_map(static fn (int $position): string => '1-' . $position, range(1, 18)),
            ...array_map(static fn (int $position): string => '2-' . $position, range(1, 8)),
        ]);
        $new = array_last($this->songs);
        $this->musicBrainz->releases[$release->id] = $release;

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        self::assertSame([], $this->searches(), 'A matched album is not searched again.');
        $command = $this->applied[0];
        self::assertSame($release->id, $command->releaseIdSeen);
        self::assertSame(
            [[$new?->getId()->toString(), '2-9']],
            array_map(static fn (TrackPair $pair): array => [$pair->local->id, $pair->release->key()], $command->candidate->pairs->pairs),
        );
    }

    public function testANewSongOfAMatchedAlbumPairsWithTheTrackAtItsNumberOnlyWithinTheLengthTolerance(): void
    {
        $release = self::release(self::releaseId(1));
        $album = $this->longBed(missing: ['2-8', '2-9']);
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new DateTimeImmutable('-1 hour'));
        $this->checkAll();
        [$fits, $differs] = $this->addSongsOf($album, $release, missing: [
            ...array_map(static fn (int $position): string => '1-' . $position, range(1, 18)),
            ...array_map(static fn (int $position): string => '2-' . $position, range(1, 7)),
        ]);
        $differs->updateLength(($release->media[1]->tracks[8]->lengthMs ?? 0) / 1000 + 40.0);
        $this->musicBrainz->releases[$release->id] = $release;

        $outcome = $this->match($album);

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        $pairs = $this->applied[0]->candidate->pairs;
        self::assertSame(
            [[$fits->getId()->toString(), '2-8']],
            array_map(static fn (TrackPair $pair): array => [$pair->local->id, $pair->release->key()], $pairs->pairs),
        );
        self::assertSame(
            [$differs->getId()->toString()],
            array_column($pairs->unpairedLocal, 'id'),
            'The song is left unpaired, so the apply marks it checked and writes nothing to it.',
        );
    }

    public function testAMatchedAlbumWithoutNewSongsTakesItsMatchTimeFromAnEmptyApply(): void
    {
        $release = self::release(self::releaseId(1));
        $album = $this->longBed();
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new DateTimeImmutable('-1 hour'));
        $this->checkAll();
        $this->musicBrainz->releases[$release->id] = $release;

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $this->match($album)->kind);
        self::assertSame([], $this->applied[0]->candidate->pairs->pairs);
    }

    /**
     * A song imported while a match ran is older than the album's match time, but the match never
     * compared it, so the next match pairs it.
     */
    public function testASongTheLastMatchDidNotCompareIsPairedThoughItIsOlderThanTheMatch(): void
    {
        $release = self::release(self::releaseId(1));
        $album = $this->longBed(missing: ['2-9']);
        $this->checkAll();
        $this->addSongsOf($album, $release, missing: [
            ...array_map(static fn (int $position): string => '1-' . $position, range(1, 18)),
            ...array_map(static fn (int $position): string => '2-' . $position, range(1, 8)),
        ]);
        $missed = array_last($this->songs);
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new DateTimeImmutable('+1 hour'));
        $this->musicBrainz->releases[$release->id] = $release;

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $this->match($album)->kind);
        self::assertSame(
            [[$missed?->getId()->toString(), '2-9']],
            array_map(static fn (TrackPair $pair): array => [$pair->local->id, $pair->release->key()], $this->applied[0]->candidate->pairs->pairs),
        );
    }

    public function testAMatchedAlbumWhoseReleaseMusicBrainzNoLongerHasIsLeftForReview(): void
    {
        $album = $this->longBed();
        $album->recordReleaseMatch(MusicbrainzId::fromString(self::releaseId(1)) ?? throw new \LogicException(), new DateTimeImmutable('-1 hour'));

        self::assertEquals(AutomaticMatchOutcome::needsReview(AlbumMatchReviewReason::NoCandidates, null, 27), $this->match($album));
        self::assertSame([], $this->applied);
    }

    public function testADeletedAlbumIsGoneWithoutAskingMusicBrainz(): void
    {
        $outcome = $this->matcher()->match(new Uuid());

        self::assertSame(AutomaticMatchOutcomeKind::AlbumGone, $outcome->kind);
        self::assertSame([], $this->musicBrainz->calls);
    }

    /** @return iterable<string, array{MatchApplyOutcome, AutomaticMatchOutcome}> */
    public static function applyOutcomes(): iterable
    {
        yield 'gone during the lookups' => [MatchApplyOutcome::AlbumGone, AutomaticMatchOutcome::albumGone()];
        yield 'matched by an admin meanwhile' => [MatchApplyOutcome::AlbumMatchedMeanwhile, AutomaticMatchOutcome::matched()];
        yield 'changed while the apply waited' => [MatchApplyOutcome::Stale, AutomaticMatchOutcome::retryLater(900)];
        yield 'no answer from the Cover Art Archive' => [MatchApplyOutcome::Unavailable, AutomaticMatchOutcome::unavailable()];
    }

    #[DataProvider('applyOutcomes')]
    public function testAnApplyThatWritesNothingEndsAsItsOutcomeSays(MatchApplyOutcome $applied, AutomaticMatchOutcome $expected): void
    {
        $album = $this->longBed();
        $this->found(self::release(self::releaseId(1)));
        $this->applyResult = static fn (ApplyMatchCommand $command): MatchApplyResult => MatchApplyResult::notApplied($applied, null, $command->candidateId);

        self::assertEquals($expected, $this->match($album));
    }

    public function testAnApplyThatLeftOutTheCoverTheCoverArtArchiveRefusedEndsAsMatched(): void
    {
        $album = $this->longBed();
        $this->found(self::release(self::releaseId(1)));
        $this->applyResult = static fn (ApplyMatchCommand $command): MatchApplyResult => MatchApplyResult::applied(MatchItemType::Album, 'album', $command->candidateId, [], true, 27, [], [])
            ->withCoverRefused();

        self::assertEquals(AutomaticMatchOutcome::matched(), $this->match($album));
    }

    public function testADeadlockedApplyRetriesThisAlbumAfterAQuarterHour(): void
    {
        $album = $this->longBed();
        $this->found(self::release(self::releaseId(1)));
        $this->applyResult = static function (ApplyMatchCommand $command): never {
            $deadlock = new TransactionDeadlockException(new \RuntimeException('SQLSTATE[40P01]: deadlock detected'));

            throw new HandlerFailedException(new Envelope($command), [$deadlock]);
        };

        self::assertEquals(AutomaticMatchOutcome::retryLater(900), $this->match($album));
    }

    /** @param list<string> $missing "<disc>-<position>" of tracks the album has no song for */
    private function longBed(array $missing = []): Album
    {
        $album = $this->addAlbum();
        $this->addSongsOf($album, self::release(self::releaseId(1)), $missing);
        $this->catalog->creditAlbum($album, $this->catalog->artist(self::ALBUM_ARTIST), ArtistRole::Primary);

        return $album;
    }

    /**
     * "Ten Thousand Fists" by Disturbed with a song per track of the release, stored with its label,
     * catalogue number and country at the end of its title.
     */
    private function tenThousandFists(MusicBrainzRelease $release): Album
    {
        $album = $this->addAlbum(self::RELEASE_TEXT_TITLE);
        $this->addSongsOf($album, $release);
        $this->catalog->creditAlbum($album, $this->catalog->artist(self::DISTURBED), ArtistRole::Primary);

        return $album;
    }

    /** The release as MusicBrainz titles it: "Ten Thousand Fists" by Disturbed, 14 tracks. */
    private static function tenThousandFistsRelease(): MusicBrainzRelease
    {
        return self::releaseWith(self::release(self::releaseId(1), [14], title: self::SHORTENED_TITLE), artistCredit: self::credit(self::DISTURBED));
    }

    private function found(MusicBrainzRelease ...$releases): void
    {
        $this->musicBrainz->releaseSearches[self::SEARCH] = array_map(static fn (MusicBrainzRelease $release): MusicBrainzReleaseSummary => self::summary($release), $releases);
        foreach ($releases as $release) {
            $this->musicBrainz->releases[$release->id] = $release;
        }
    }

    private function match(Album $album): AutomaticMatchOutcome
    {
        return $this->matcher()->match($album->getId());
    }

    private function matcher(): AutomaticAlbumMatcher
    {
        return new AutomaticAlbumMatcher(
            $this->albumPort(),
            $this->snapshots(),
            $this->musicBrainz,
            new AlbumReleaseSearch($this->musicBrainz),
            $this->scorer(),
            new MatchConfidence(),
            $this->bus(),
        );
    }

    /** @return list<string> the title and artist of each release search */
    private function searches(): array
    {
        return array_filter($this->musicBrainz->calls, static fn (string $call): bool => str_starts_with($call, 'findReleases:'))
            |> (static fn (array $calls): array => array_map(static fn (string $call): string => substr($call, strlen('findReleases:')), $calls))
            |> array_values(...);
    }

    private function albumPort(): AlbumPortInterface
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findFreshByUuid')->willReturnCallback(
            fn (Uuid $id): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getId()->equals($id)),
        );

        return $albums;
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $command): Envelope {
            self::assertInstanceOf(ApplyMatchCommand::class, $command);
            $this->applied[] = $command;
            $result = $this->applyResult === null
                ? MatchApplyResult::applied(MatchItemType::Album, 'album', $command->candidateId, [], true, 27, [], [])
                : ($this->applyResult)($command);

            return new Envelope($command, [new HandledStamp($result, 'ApplyMatchHandler::__invoke')]);
        });

        return $bus;
    }

    /** Marks every song the album has now as compared by a match, as an apply does. */
    private function checkAll(): void
    {
        foreach ($this->songs as $song) {
            $song->markMatchChecked(new DateTimeImmutable('-1 hour'));
        }
    }
}
