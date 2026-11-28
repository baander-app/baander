<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyOutcome;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\ApplyMatchHandler;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\MatchCovers;
use App\Filesystem\Mime\MimeDetector;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\MatchTicks;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Application\Port\ArtistResolution;
use App\Catalog\Application\Port\ArtistSyncRequestInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\IdentifiedArtist;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\ArtistSyncRequester;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchRequester;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumReleaseSearch;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcher;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchConfidence;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairs;
use App\Catalog\Application\Service\MusicBrainzMatch\ArtistMatchWriter;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\StoredFile;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use App\Tests\Fixtures\Catalog\InMemoryQueuedArtistSyncs;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use Closure;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Applies MusicBrainz matches over a library held in memory behind the catalog ports, with the
 * comparison an admin would see from the comparison query. The ports record what the apply asked
 * of them, so a test can tell what it locked, resolved, merged and removed.
 */
final class ApplyMatchHandlerTest extends TestCase
{
    use MatchQueryFixtures;

    /** The guest on each of the album's duets, by its track on the first disc. */
    private const array GUESTS = [
        1 => 'Morgan Wallen', 2 => 'Blake Shelton', 3 => 'Luke Combs', 4 => 'Lainey Wilson',
        5 => 'Hank Williams Jr.', 6 => 'Chris Stapleton', 7 => 'Jelly Roll', 8 => 'Dolly Parton',
        9 => 'Tim McGraw', 10 => 'Brad Paisley', 11 => 'Sierra Ferrell', 12 => 'Josh Turner',
        13 => 'Hardy', 14 => 'Tyler Childers',
    ];

    private const string HEADHUNTERZ_ID = '6c1e0000-0000-4000-8000-000000000001';

    /** The time on the apply's clock. */
    private const string NOW = '2026-11-14 12:00:00';

    /** A PNG of one pixel. */
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private CoverServingLookup $lookup;

    /** @var list<string> what the apply asked of the ports, in order */
    private array $calls = [];

    /** @var array<string, string> genre names by genre ID */
    private array $genreNames = [];

    /** @var array<string, Image> images saved, by ID */
    private array $savedImages = [];

    /** @var array<string, array<string, mixed>> the context of each entry the apply logged, by its message */
    private array $logged = [];

    /** Runs when the apply locks the album's row, as a writer that committed while the apply waited for it. */
    private ?Closure $whileWaitingForTheAlbum = null;

    /** Fails the apply's transaction instead of running it. */
    private ?Throwable $transactionFailure = null;

    /** Fails every artist merge, after the apply wrote everything else. */
    private ?Throwable $mergeFailure = null;

    /** Reports a failed commit after the operation's writes were committed after all. */
    private ?Throwable $commitFailure = null;

    /** Fails the lookup of a saved image, as a database that cannot be reached. */
    private ?Throwable $imageLookupFailure = null;

    /** The album match states the apply removes and its match request writes. */
    private InMemoryAlbumMatchStates $states;

    private bool $automaticMatching = false;

    /** @var list<int|null> the delay of each album match queue run a request asked for, in milliseconds */
    private array $queuedRuns = [];

    /** Fails every match request, as an album match queue that cannot be reached. */
    private ?Throwable $requestFailure = null;

    /** The marks every producer of artist fills shares. */
    private InMemoryQueuedArtistSyncs $fillMarks;

    /** @var list<array{string, bool}> the artist of each fill queued, and whether the apply's transaction was open then */
    private array $queuedFills = [];

    /** Fails queuing every artist fill, as a transport that cannot be reached. */
    private ?Throwable $fillFailure = null;

    /** Whether the apply's transaction is running. */
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $this->setUpLibrary();
        $this->lookup = new CoverServingLookup($this->musicBrainz);
        $this->states = new InMemoryAlbumMatchStates(new MockClock(self::NOW));
        $this->fillMarks = new InMemoryQueuedArtistSyncs();
    }

    public function testAConfidentMatchOfLongBedCreditsEachDuetToPostMaloneAndItsGuestAndMergesEachCombinedArtistIntoPostMalone(): void
    {
        $album = $this->addAlbum();
        [$release, $songs, $postMalone, $duetArtists] = $this->longBed($album);

        $applied = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        foreach ($songs as $song) {
            $guest = self::guestOf($song);
            $expected = $guest === null
                ? [['Post Malone', 'primary', 'Post Malone']]
                : [['Post Malone', 'primary', 'Post Malone, ' . $guest], [$guest, 'featured', $guest]];
            self::assertSame($expected, $this->creditsOf($song), 'The duet keeps showing its tagged text; its guest is featured.');
            self::assertSame(self::recordingId($song->getDisc() ?? 0, $song->getTrack() ?? 0), $song->getMbid());
            self::assertTrue($song->isFieldMatched(MatchedSongField::Credits));
        }
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertNotNull($album->getMatchedAt());
        self::assertSame([$postMalone], $this->artistsNamed('Post Malone'), 'The ID-less Post Malone took on the ID; no second one was made.');
        self::assertSame(self::artistId('Post Malone'), $postMalone->getMbid());
        foreach ($duetArtists as $duetArtist) {
            self::assertNull($this->catalogArtist($duetArtist->getId()), $duetArtist->getName() . ' merged away.');
        }
        self::assertCount(14, $applied->mergedArtists);
        self::assertSame([], $applied->removedArtists);
        self::assertSame(27, $applied->songsChanged);
        self::assertNotContains('remove match state', $this->calls, 'The album match queue removes the request it claimed.');
        self::assertSame(
            ['lock album', 'lock identities', 'lock artists', 'lock songs', 'resolve'],
            array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, 'lock') || $call === 'resolve') |> array_values(...) |> (static fn (array $calls): array => array_slice($calls, 0, 5)),
            'The apply locks in order before it resolves anything.',
        );
    }

    public function testWithTheYearUntickedAndTheGenreTickedTheGenreIsFilledAndTheYearKept(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = $this->countryRelease();
        $this->addSongsOf($album, $release);

        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        self::assertFalse($comparison->field(MatchField::Year)?->ticked, 'A year that differs is unticked.');
        ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertSame(2023, $album->getYear());
        self::assertSame(['Country'], $this->catalog->albumGenres[$album->getId()->toString()] ?? []);
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertContains('remove match state', $this->calls, 'A manual apply takes the album off the review list.');
    }

    public function testAFieldChangedWhileTheApplyWaitedForItsLocksAnswersStaleAndWritesNothing(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = $this->countryRelease();
        $this->addSongsOf($album, $release);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        $selection = MatchSelections::defaults($comparison);
        $selection = new MatchSelection([...$selection->fields, new SeenField(MatchField::Year, 2023)], $selection->credits);
        $this->whileWaitingForTheAlbum = static fn () => $album->updateMetadata(year: 2022);

        $conflict = $this->expectConflict(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, $selection) |> $this->apply(...));

        self::assertSame('match_stale', $conflict->details['reason'] ?? null);
        self::assertSame(2022, $album->getYear());
        self::assertNull($album->getReleaseMbid());
        self::assertSame([], $this->catalog->albumGenres[$album->getId()->toString()] ?? []);
        self::assertNotContains('resolve', $this->calls);
    }

    /** The cover row shows only whether the album has a cover, and the apply rechecks just that. */
    public function testACoverTickedWhileTheAlbumHadNoneAnswersStaleOnceTheAlbumTookOne(): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        self::assertSame([null, true], [$comparison->field(MatchField::Cover)?->current, $comparison->field(MatchField::Cover)?->ticked]);
        $taken = new Uuid();
        $this->whileWaitingForTheAlbum = static fn () => $album->setCoverImage($taken);

        $conflict = $this->expectConflict(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...));

        self::assertSame('match_stale', $conflict->details['reason'] ?? null);
        self::assertSame($taken->toString(), $album->getCoverImageId()?->toString());
        self::assertNull($album->getReleaseMbid());
    }

    public function testReplacingACoverTheAdminSawStoresTheReleasesFrontCover(): void
    {
        $album = $this->addAlbum();
        $old = new Uuid();
        $album->setCoverImage($old);
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        self::assertSame([true, false, true], [$comparison->field(MatchField::Cover)?->current, $comparison->field(MatchField::Cover)?->ticked, $comparison->field(MatchField::Cover)?->changes()]);

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison, [MatchField::Cover])) |> $this->apply(...);

        self::assertContains('cover', $applied->fields);
        self::assertNotNull($album->getCoverImageId());
        self::assertNotSame($old->toString(), $album->getCoverImageId()->toString());
    }

    public function testAManualApplyWhoseTickedCoverTheCoverArtArchiveRefusesWritesNothingAndAppliesOnceTheCoverIsUnticked(): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzRejected(403);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        $ticked = MatchSelections::defaults($comparison);
        self::assertContains(MatchField::Cover, array_column($ticked->fields, 'field'), 'An album without a cover has the cover ticked.');

        $conflict = $this->expectConflict(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, $ticked) |> $this->apply(...));

        self::assertSame('cover_refused', $conflict->details['reason'] ?? null);
        self::assertSame('The Cover Art Archive refused the cover with HTTP 403. Nothing was written; untick the cover to apply the rest.', $conflict->getMessage());
        self::assertNull($album->getReleaseMbid());
        self::assertSame([], array_intersect(['lock album', 'store file'], $this->calls), 'The apply ended before its transaction and wrote no file.');

        $unticked = new MatchSelection(
            array_filter($ticked->fields, static fn (SeenField $field): bool => $field->field !== MatchField::Cover) |> array_values(...),
            $ticked->credits,
            $ticked->artistActions,
            $ticked->keptArtists,
        );
        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, $unticked) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertFalse($applied->coverRefused);
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertNull($album->getCoverImageId());
    }

    public function testAManualApplyOfTheReleaseAnAlbumHoldsWithOnlyTheCoverTickedSavesTheCover(): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new \DateTimeImmutable('2026-11-01 08:00:00'));
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        self::assertSame([null, true], [$comparison->field(MatchField::Cover)?->current, $comparison->field(MatchField::Cover)?->ticked]);

        $applied = ApplyMatchCommand::manual(
            MatchItemType::Album,
            $album->getPublicId()->toString(),
            $release->id,
            new MatchSelection([new SeenField(MatchField::Cover, null)]),
        ) |> $this->apply(...);

        self::assertSame(['cover'], $applied->fields);
        $coverId = $album->getCoverImageId()?->toString();
        self::assertNotNull($coverId);
        self::assertArrayHasKey($coverId, $this->savedImages);
        self::assertSame($release->id, $album->getReleaseMbid());
    }

    public function testAMergeTheAdminWasNotShownAnswersStale(): void
    {
        $album = $this->addAlbum();
        [$release, $songs] = $this->longBed($album);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        $shown = MatchSelections::defaults($comparison);

        $conflict = $this->expectConflict(fn () => ApplyMatchCommand::manual(
            MatchItemType::Album,
            $album->getPublicId()->toString(),
            $release->id,
            new MatchSelection($shown->fields, $shown->credits),
        ) |> $this->apply(...));

        self::assertSame('match_stale', $conflict->details['reason'] ?? null);
        self::assertSame([['Post Malone, Morgan Wallen', 'primary', 'Post Malone, Morgan Wallen']], $this->creditsOf($songs[0]));
    }

    public function testASongMatchSetsTheSongsRecordingIdTitleAndCreditsAndLeavesItsAlbumAlone(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::release(self::releaseId(1));
        [$song] = $this->addSongsOf($album, $release);
        $postMalone = $this->catalog->artist('Post Malone');
        $this->catalog->creditSong($song, $postMalone, ArtistRole::Primary);
        $recording = new MusicBrainzRecording('5ec0bd00-1111-4000-8000-000000000001', 'Wrong Ones', 100, 150_000, null, self::credit('Post Malone', 'Tim McGraw'), []);
        $this->musicBrainz->recordings[$recording->id] = $recording;

        $comparison = $this->compare(MatchItemType::Song, $song->getPublicId()->toString(), $recording->id);
        self::assertFalse($comparison->field(MatchField::Title)?->ticked, 'A title that differs is unticked; the admin ticks it.');
        $shown = MatchSelections::defaults($comparison);
        $selection = new MatchSelection([...$shown->fields, new SeenField(MatchField::Title, 'Track 1-1')], $shown->credits);
        $applied = ApplyMatchCommand::manual(MatchItemType::Song, $song->getPublicId()->toString(), $recording->id, $selection) |> $this->apply(...);

        self::assertSame($recording->id, $song->getMbid());
        self::assertSame('Wrong Ones', $song->getTitle());
        self::assertSame([['Post Malone', 'primary', 'Post Malone'], ['Tim McGraw', 'featured', 'Tim McGraw']], $this->creditsOf($song));
        self::assertEqualsCanonicalizing([MatchedSongField::Title, MatchedSongField::Credits], $song->getMatchedFields());
        self::assertSame($album->getId()->toString(), $song->getAlbumId()->toString());
        self::assertNull($album->getReleaseMbid());
        self::assertSame(['mbid', 'title'], $applied->fields);
        self::assertNotContains('lock album', $this->calls);
        self::assertNotContains('remove match state', $this->calls);
    }

    public function testAnArtistMatchGivingTheIdAnotherArtistHoldsMergesTheArtistIntoThatHolderWithItsCover(): void
    {
        $headHunterz = $this->catalog->artist('HeadHunterz');
        $cover = new Uuid();
        $headHunterz->setCoverImage($cover);
        $headhunterz = $this->catalog->artist('Headhunterz', self::HEADHUNTERZ_ID);
        $this->musicBrainz->artists[self::HEADHUNTERZ_ID] = self::headhunterz();

        $comparison = $this->compare(MatchItemType::Artist, $headHunterz->getPublicId()->toString(), self::HEADHUNTERZ_ID);
        self::assertTrue($comparison->artistActions[0]->ticked ?? false, 'The merge is listed, ticked.');
        $applied = ApplyMatchCommand::manual(MatchItemType::Artist, $headHunterz->getPublicId()->toString(), self::HEADHUNTERZ_ID, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertSame([$headHunterz->getPublicId()->toString()], $applied->mergedArtists);
        self::assertSame([$headhunterz], $this->catalog->artists);
        self::assertSame($cover->toString(), $headhunterz->getCoverImageId()?->toString(), 'The holder had no cover and took the merged artist\'s.');
        self::assertContains('assign owner ' . $cover->toString() . ' to Headhunterz', $this->calls);
    }

    public function testUntickingTheMergeLeavesBothArtistsAsTheyWere(): void
    {
        $headHunterz = $this->catalog->artist('HeadHunterz');
        $headhunterz = $this->catalog->artist('Headhunterz', self::HEADHUNTERZ_ID);
        $this->musicBrainz->artists[self::HEADHUNTERZ_ID] = self::headhunterz();

        $this->compare(MatchItemType::Artist, $headHunterz->getPublicId()->toString(), self::HEADHUNTERZ_ID);
        $applied = ApplyMatchCommand::manual(
            MatchItemType::Artist,
            $headHunterz->getPublicId()->toString(),
            self::HEADHUNTERZ_ID,
            new MatchSelection(keptArtists: [$headHunterz->getPublicId()->toString()]),
        ) |> $this->apply(...);

        self::assertSame([], $applied->mergedArtists);
        self::assertSame([$headHunterz, $headhunterz], $this->catalog->artists);
        self::assertNull($headHunterz->getMbid());
        self::assertSame([], array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, 'merge')));
    }

    public function testAMatchWithoutAnAdminFillsOnlyEmptyFieldsAndAMissingCover(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');

        $applied = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertSame(2023, $album->getYear(), 'A year that holds a value is never replaced.');
        self::assertSame(['Country'], $this->catalog->albumGenres[$album->getId()->toString()] ?? []);
        $coverId = $album->getCoverImageId()?->toString();
        self::assertNotNull($coverId);
        self::assertSame(1, $this->savedImages[$coverId]->getWidth());
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertEqualsCanonicalizing(['releaseMbid', 'mbid', 'country', 'genres', 'cover'], $applied->fields);
        self::assertFalse($applied->coverRefused);
    }

    public function testAnAutomaticMatchContinuesWithoutCoverArtWhenItsProviderIsDisabled(): void
    {
        $album = $this->addAlbum();
        [$release] = $this->longBed($album);
        $release = self::releaseWith($release, hasFrontCover: true);
        $this->lookup->covers[$release->id] = new MusicBrainzDisabled('coverartarchive');

        $applied = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertFalse($applied->coverRefused);
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertNotContains('cover', $applied->fields);
        self::assertNull($album->getCoverImageId());
        self::assertSame([], $this->savedImages);
        self::assertSame([], array_intersect(['store file', 'delete file'], $this->calls));
    }

    public function testAMatchWithoutAnAdminIsAppliedWithoutTheCoverTheCoverArtArchiveRefuses(): void
    {
        $album = $this->addAlbum();
        [$release, $songs] = $this->longBed($album);
        $release = self::releaseWith($release, hasFrontCover: true);
        $this->lookup->covers[$release->id] = new MusicBrainzRejected(403);

        $applied = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertTrue($applied->coverRefused);
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertNotNull($album->getMatchedAt());
        self::assertContains('releaseMbid', $applied->fields);
        self::assertNotContains('cover', $applied->fields);
        self::assertSame(self::recordingId(1, 1), $songs[0]->getMbid());
        self::assertSame(
            [['Post Malone', 'primary', 'Post Malone, Morgan Wallen'], ['Morgan Wallen', 'featured', 'Morgan Wallen']],
            $this->creditsOf($songs[0]),
        );
        self::assertCount(14, $applied->mergedArtists);
        self::assertNull($album->getCoverImageId());
        self::assertSame([], $this->savedImages, 'No image is stored.');
        self::assertSame([], array_intersect(['store file', 'delete file'], $this->calls), 'No file is written, so none is left.');
        self::assertEquals(
            ['public_id' => $album->getPublicId()->toString(), 'candidate_id' => $release->id, 'cover_refused' => true],
            array_intersect_key($this->logged['Applied a MusicBrainz match'] ?? [], ['public_id' => 0, 'candidate_id' => 0, 'cover_refused' => 0]),
            'The log entry of the apply names the album and the release whose cover was refused.',
        );
    }

    public function testTheFileOfACoverSavedBeforeTheApplyFailedIsRemovedWithTheRolledBackImage(): void
    {
        $album = $this->addAlbum();
        [$release] = $this->longBed($album);
        $release = self::releaseWith($release, hasFrontCover: true);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $this->mergeFailure = new \RuntimeException('The merge failed after the cover was saved.');

        try {
            ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);
            self::fail('The failed merge fails the apply.');
        } catch (\RuntimeException $failure) {
            self::assertSame($this->mergeFailure, $failure);
        }

        self::assertSame([], $this->savedImages, 'The image row was rolled back.');
        self::assertContains('delete file', $this->calls, 'Its stored file is removed as well.');
    }

    public function testTheFileOfACoverCommittedDespiteAFailedCommitIsKept(): void
    {
        $album = $this->addAlbum();
        [$release] = $this->longBed($album);
        $release = self::releaseWith($release, hasFrontCover: true);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $this->commitFailure = new \RuntimeException('The connection closed while the commit was answered.');

        try {
            ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);
            self::fail('The failed commit fails the apply.');
        } catch (\RuntimeException $failure) {
            self::assertSame($this->commitFailure, $failure);
        }

        self::assertCount(1, $this->savedImages, 'The image row was committed.');
        self::assertNotContains('delete file', $this->calls, 'The committed cover keeps its file.');
    }

    public function testTheFileOfACoverIsKeptWhenItCannotBeToldWhetherTheCoverWasCommitted(): void
    {
        $album = $this->addAlbum();
        [$release] = $this->longBed($album);
        $release = self::releaseWith($release, hasFrontCover: true);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $this->mergeFailure = new \RuntimeException('The merge failed after the cover was saved.');
        $this->imageLookupFailure = new \RuntimeException('The database cannot be reached.');

        try {
            ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);
            self::fail('The failed merge fails the apply.');
        } catch (\RuntimeException $failure) {
            self::assertSame($this->mergeFailure, $failure);
        }

        self::assertNotContains('delete file', $this->calls, 'A cover that may have been committed keeps its file.');
    }

    public function testAMatchMarksEverySongItComparedAndLeavesASongAddedMeanwhileForTheNextMatch(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $songs = $this->addSongsOf($album, $release, missing: ['2-9']);
        $candidate = $this->candidate($album, $release);
        [$added] = $this->addSongsOf($album, $release, missing: array_map(static fn (Song $song): string => $song->getDisc() . '-' . $song->getTrack(), $songs));

        ApplyMatchCommand::automatic($album->getId(), $candidate) |> $this->apply(...);

        self::assertTrue(array_all($songs, static fn (Song $song): bool => $song->getMatchCheckedAt() == new \DateTimeImmutable('2026-11-14 12:00:00')), 'Every song the candidate compared is checked.');
        self::assertNull($added->getMatchCheckedAt(), 'A song imported after the matcher read the album is left for the next match.');
        self::assertNull($added->getMbid());
    }

    public function testAMatchWithoutAnAdminWritesNothingWhenTheAlbumTookAReleaseMeanwhile(): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith(self::release(self::releaseId(1)), hasFrontCover: true);
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $this->whileWaitingForTheAlbum = static fn () => $album->recordReleaseMatch(MusicbrainzId::fromString(self::releaseId(2)) ?? throw new \LogicException(), new \DateTimeImmutable());

        $result = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertSame(MatchApplyOutcome::AlbumMatchedMeanwhile, $result->outcome);
        self::assertSame(self::releaseId(2), $album->getReleaseMbid());
        self::assertNull($album->getCoverImageId());
        self::assertContains('delete file', $this->calls, 'The downloaded cover file is removed again.');
    }

    /** @param string $answer what the Cover Art Archive adapter reports: a server error, a throttled request, or one that timed out */
    #[TestWith(['HTTP 503'])]
    #[TestWith(['HTTP 429'])]
    #[TestWith(['the request failed'])]
    public function testAMatchWithoutAnAdminReportsACoverArchiveOutageAndWritesNothing(string $answer): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith(self::release(self::releaseId(1)), hasFrontCover: true);
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzUnavailable($answer);

        $result = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertSame(MatchApplyOutcome::Unavailable, $result->outcome);
        self::assertNull($album->getReleaseMbid());
        self::assertNotContains('lock album', $this->calls);
    }

    public function testAMatchedAlbumPairedAgainstItsStoredReleaseTakesANewMatchTimeEvenWithoutANewSong(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->addSongsOf($album, $release);
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), new \DateTimeImmutable('2026-11-01 08:00:00'));
        $candidate = new MatchCandidate($release, 100, new TrackPairs([], [], []), [$release]);

        $result = ApplyMatchCommand::automatic($album->getId(), $candidate, $release->id) |> $this->apply(...);

        self::assertTrue($result->isApplied());
        self::assertSame('2026-11-14 12:00:00', $album->getMatchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(0, $result->songsChanged);
        self::assertNotContains('remove match state', $this->calls);
    }

    public function testAMatchedAlbumWithoutANewSongKeepsTheCreditsAnAdminGaveItAndTakesANewMatchTime(): void
    {
        $album = $this->addAlbum();
        $release = $this->musicBrainz->releases[self::releaseId(1)] = self::release(self::releaseId(1));
        $this->addSongsOf($album, $release);
        $this->matchWith($album, $release);
        $austinPost = $this->catalog->artist('Austin Post');
        $this->catalog->creditAlbum($album, $austinPost, ArtistRole::Primary);

        $result = $this->matcherApplyOf($album) |> $this->apply(...);

        self::assertTrue($result->isApplied());
        self::assertSame([['Austin Post', 'primary', 'Austin Post']], $this->albumCreditsOf($album), 'The release credits Post Malone; the album keeps the credit it holds.');
        self::assertFalse($result->creditsChanged);
        self::assertSame($austinPost, $this->catalogArtist($austinPost->getId()));
        self::assertSame([[], []], [$result->mergedArtists, $result->removedArtists]);
        self::assertSame('2026-11-14 12:00:00', $album->getMatchedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAMatchOfAMatchedAlbumsNewSongCreditsTheSongAndKeepsTheArtistOnlyTheAlbumCredits(): void
    {
        [$album, $new, $austinPost, $duetArtist] = $this->matchedLongBedWithANewDuet();

        $result = $this->matcherApplyOf($album) |> $this->apply(...);

        self::assertSame(
            [['Post Malone', 'primary', 'Post Malone, Tim McGraw'], ['Tim McGraw', 'featured', 'Tim McGraw']],
            $this->creditsOf($new),
        );
        self::assertSame(self::recordingId(2, 9), $new->getMbid());
        self::assertSame([['Austin Post', 'primary', 'Austin Post']], $this->albumCreditsOf($album), 'The album keeps the credit it holds.');
        self::assertFalse($result->creditsChanged);
        self::assertSame($austinPost, $this->catalogArtist($austinPost->getId()), 'Only the album credits Austin Post; the artist is neither merged nor removed.');
        self::assertSame([[$duetArtist->getPublicId()->toString()], []], [$result->mergedArtists, $result->removedArtists], 'The new song\'s combined artist still merges into Post Malone.');
        self::assertSame(1, $result->songsChanged);
    }

    public function testTheRowsOfAMatchOfAMatchedAlbumsNewSongsHoldNeitherTheAlbumsCreditsNorAMergeThatNeedsThem(): void
    {
        [$album, $new] = $this->matchedLongBedWithANewDuet();
        $candidate = $this->matcherApplyOf($album)->candidate ?? throw new \LogicException('The matcher applies a candidate.');
        $comparison = new MatchComparisonBuilder($this->catalog, $this->catalogArtists())->album($this->snapshotsOverPorts()->ofAlbum($album), $candidate);

        $first = MatchTicks::automatic($comparison, ofMatchedAlbum: false);
        $ofNewSongs = MatchTicks::automatic($comparison, ofMatchedAlbum: true);

        self::assertNotNull($first->creditsOf(null), 'An album\'s first match writes the album\'s credits.');
        self::assertSame(['Austin Post', 'Post Malone, Tim McGraw'], array_column($first->actions, 'artistName'));
        self::assertNull($ofNewSongs->creditsOf(null));
        self::assertNotNull($ofNewSongs->creditsOf($new->getPublicId()->toString()));
        self::assertSame(['Post Malone, Tim McGraw'], array_column($ofNewSongs->actions, 'artistName'));
        self::assertSame($first->fields, $ofNewSongs->fields);
        self::assertSame($first->songFields, $ofNewSongs->songFields);
    }

    public function testTheFirstMatchWithoutAnAdminReplacesTheAlbumsCreditsWithTheReleases(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->addSongsOf($album, $release);
        $austinPost = $this->catalog->artist('Austin Post');
        $this->catalog->creditAlbum($album, $austinPost, ArtistRole::Primary);

        $result = ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);

        self::assertSame([['Post Malone', 'primary', 'Austin Post']], $this->albumCreditsOf($album), 'The primary credit keeps the name the album showed.');
        self::assertTrue($result->creditsChanged);
        self::assertSame([$austinPost->getPublicId()->toString()], $result->mergedArtists);
    }

    public function testAMatchOfAMatchedAlbumsNewSongsStillFillsTheAlbumsEmptyFieldsAndMissingCover(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::releaseWith($this->countryRelease(), hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $this->matchWith($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');

        $result = $this->matcherApplyOf($album) |> $this->apply(...);

        self::assertSame(2023, $album->getYear(), 'A year that holds a value is never replaced.');
        self::assertSame('XW', $album->getCountry());
        self::assertSame(['Country'], $this->catalog->albumGenres[$album->getId()->toString()] ?? []);
        self::assertArrayHasKey($album->getCoverImageId()?->toString() ?? '', $this->savedImages);
        self::assertEqualsCanonicalizing(['mbid', 'country', 'genres', 'cover'], $result->fields);
    }

    public function testAMatchOfAMatchedAlbumsNewSongWritesNothingOnceAnotherMatchComparedTheSong(): void
    {
        [$album, $new] = $this->matchedLongBedWithANewDuet();
        $release = self::releaseWith($this->musicBrainz->releases[self::releaseId(1)], hasFrontCover: true);
        $this->musicBrainz->releases[$release->id] = $release;
        $this->lookup->covers[$release->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');
        $command = $this->matcherApplyOf($album);
        $comparedAt = new \DateTimeImmutable('2026-11-14 11:59:00');
        $this->whileWaitingForTheAlbum = static fn () => $new->markMatchChecked($comparedAt);

        $result = $this->apply($command);

        self::assertSame([['Post Malone, Tim McGraw', 'primary', 'Post Malone, Tim McGraw']], $this->creditsOf($new), 'The song keeps the credits the other match left it.');
        self::assertSame(MatchApplyOutcome::Stale, $result->outcome);
        self::assertNull($new->getMbid());
        self::assertSame($comparedAt, $new->getMatchCheckedAt());
        self::assertSame('2026-11-14 11:00:00', $album->getMatchedAt()?->format('Y-m-d H:i:s'), 'The album keeps its match time.');
        self::assertNull($album->getCoverImageId());
        self::assertNotContains('resolve', $this->calls);
        self::assertContains('delete file', $this->calls, 'The downloaded cover file is removed again.');
    }

    public function testAMatchOfAMatchedAlbumsNewSongsWritesNothingOnceTheSongsPairWithOtherTracks(): void
    {
        $album = $this->addAlbum();
        $release = $this->musicBrainz->releases[self::releaseId(1)] = self::release(self::releaseId(1));
        $compared = $this->addSongsOf($album, $release, missing: ['2-8', '2-9']);
        $this->matchWith($album, $release);
        [$eighth, $ninth] = $this->addSongsOf($album, $release, missing: array_map(self::placeOf(...), $compared));
        $command = $this->matcherApplyOf($album);
        $this->whileWaitingForTheAlbum = static function () use ($eighth, $ninth): void {
            $eighth->updateMetadata(track: 9);
            $ninth->updateMetadata(track: 8);
        };

        $result = $this->apply($command);

        self::assertSame([null, null], [$eighth->getMbid(), $ninth->getMbid()], 'Neither song takes the recording of the track it paired with before its number changed.');
        self::assertSame(MatchApplyOutcome::Stale, $result->outcome);
        self::assertSame([null, null], [$eighth->getMatchCheckedAt(), $ninth->getMatchCheckedAt()], 'Both songs stay new for the next match.');
        self::assertSame('2026-11-14 11:00:00', $album->getMatchedAt()?->format('Y-m-d H:i:s'), 'The album keeps its match time.');
        self::assertNotContains('resolve', $this->calls);
    }

    public function testAMatchOfAMatchedAlbumMarksANewSongWhoseLengthFitsNoTrackAsComparedAndWritesNothingToIt(): void
    {
        $album = $this->addAlbum();
        $release = $this->musicBrainz->releases[self::releaseId(1)] = self::release(self::releaseId(1));
        $compared = $this->addSongsOf($album, $release, missing: ['2-9']);
        $this->matchWith($album, $release);
        [$new] = $this->addSongsOf($album, $release, missing: array_map(self::placeOf(...), $compared));
        $new->updateLength(($new->getLength() ?? 0.0) + 40.0);

        $result = $this->matcherApplyOf($album) |> $this->apply(...);

        self::assertTrue($result->isApplied(), 'The song pairs with no track, as when the matcher read it.');
        self::assertNull($new->getMbid());
        self::assertEquals(new \DateTimeImmutable('2026-11-14 12:00:00'), $new->getMatchCheckedAt());
        self::assertSame(0, $result->songsChanged);
    }

    public function testADeadlockAnswersAManualApplyWithARetryableConflictAndFailsAnAutomaticOne(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        $this->transactionFailure = new TransactionDeadlockException(new \RuntimeException('SQLSTATE[40P01]: deadlock detected'));

        $conflict = $this->expectConflict(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...));
        self::assertSame('match_busy', $conflict->details['reason'] ?? null);

        $this->expectException(TransactionDeadlockException::class);
        ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);
    }

    public function testAnApplyForAnAlbumDeletedWhileItWaitedForItsLocksNamesTheItemAsTheCause(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        $this->whileWaitingForTheAlbum = function () use ($album): void {
            $this->albums = array_filter($this->albums, static fn (Album $kept): bool => $kept !== $album) |> array_values(...);
        };

        $notFound = $this->expectNotFound(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...));

        self::assertSame('The album no longer exists.', $notFound->getMessage());
        self::assertSame('item_not_found', $notFound->details['reason'] ?? null);
    }

    public function testAnApplyForACandidateMusicBrainzNoLongerHasNamesTheCandidateAsTheCause(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);
        unset($this->musicBrainz->releases[$release->id]);

        $notFound = $this->expectNotFound(fn () => ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...));

        self::assertSame('MusicBrainz has no such release.', $notFound->getMessage());
        self::assertSame('candidate_not_found', $notFound->details['reason'] ?? null);
        self::assertNull($album->getReleaseMbid());
    }

    public function testAManualApplyAsksForTheAlbumAgainWhenAnImportAddedASongItDidNotCompare(): void
    {
        $this->automaticMatching = true;

        [$album, $imported, $applied] = $this->applyByHandBesideAnImport();

        self::assertTrue($applied->isApplied());
        self::assertNotNull($album->getReleaseMbid());
        self::assertNull($imported->getMatchCheckedAt(), 'The apply did not compare the song the import added.');
        $state = $this->states->stateOf($album->getId());
        self::assertSame([AlbumMatchStatus::Requested, AlbumMatchSource::Ingest], [$state?->status, $state?->source], 'The album waits for a match again, as after an import.');
        self::assertEquals(new \DateTimeImmutable('2026-11-14 12:05:00'), $state->dueAt, 'The apply removed the import\'s request and asked anew; the request falls due after the settling delay.');
        self::assertSame([300_000], $this->queuedRuns, 'One queue run is on its way.');
    }

    public function testWhileAutomaticMatchingIsOffAManualApplyLeavesNoRequestForASongItDidNotCompare(): void
    {
        [$album, $imported, $applied] = $this->applyByHandBesideAnImport();

        self::assertTrue($applied->isApplied());
        self::assertNotNull($album->getReleaseMbid());
        self::assertNull($imported->getMatchCheckedAt());
        self::assertNull($this->states->stateOf($album->getId()));
        self::assertSame([], $this->queuedRuns);
    }

    public function testAManualApplyThatLeavesTheAlbumWithoutAReleaseLeavesNoRequest(): void
    {
        $this->automaticMatching = true;

        [$album, $imported, $applied] = $this->applyByHandBesideAnImport(releaseTicked: false);

        self::assertTrue($applied->isApplied());
        self::assertNull($album->getReleaseMbid(), 'The admin left the release ID unticked.');
        self::assertNull($imported->getMatchCheckedAt());
        self::assertNull($this->states->stateOf($album->getId()), 'A search for the album\'s release would match over the admin\'s choice.');
        self::assertSame([], $this->queuedRuns);
    }

    public function testAManualApplyThatComparedEverySongOfTheAlbumLeavesNoRequest(): void
    {
        $this->automaticMatching = true;
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releases[$release->id] = $release;
        $this->addSongsOf($album, $release);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertSame($release->id, $album->getReleaseMbid());
        self::assertNull($this->states->stateOf($album->getId()));
        self::assertSame([], $this->queuedRuns);
    }

    public function testAMatchRequestThatFailsIsLoggedWithTheAlbumAndTheApplyAnswersAsItDoesWithoutIt(): void
    {
        $this->automaticMatching = true;
        $this->requestFailure = new \RuntimeException('The album match queue cannot be reached.');

        [$album, , $applied] = $this->applyByHandBesideAnImport();

        self::assertTrue($applied->isApplied());
        self::assertSame([$album->getPublicId()->toString(), 26], [$applied->publicId, $applied->songsChanged]);
        self::assertNotNull($album->getReleaseMbid());
        self::assertSame(
            ['album_id' => $album->getId()->toString(), 'exception' => $this->requestFailure],
            $this->logged['Could not request a MusicBrainz match of album {album_id} after a manual apply; a metadata sync from the admin panel requests it again.'] ?? null,
        );
        self::assertArrayHasKey('Applied a MusicBrainz match', $this->logged, 'The apply reports itself as applied.');
    }

    public function testAnAlbumApplyQueuesOneFillForEachArtistItCreatedWithAnIdOrGaveOneOnceItCommittedWhileAutomaticMatchingIsOff(): void
    {
        $album = $this->addAlbum();
        [$release, , $postMalone] = $this->longBed($album);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertSame(self::artistId('Post Malone'), $postMalone->getMbid(), 'The ID-less Post Malone took on the ID.');
        $guests = array_map(fn (string $guest): string => $this->onlyArtistNamed($guest)->getId()->toString(), self::GUESTS) |> array_values(...);
        self::assertEqualsCanonicalizing(
            [$postMalone->getId()->toString(), ...$guests],
            array_column($this->queuedFills, 0),
            'One fill each for the artist that took on its ID and the fourteen guests created with theirs.',
        );
        self::assertNotContains(true, array_column($this->queuedFills, 1), 'Every fill is queued after the commit.');
    }

    public function testAnArtistThatAlreadyHeldItsIdQueuesNoFill(): void
    {
        [$album] = $this->matchedLongBedWithANewDuet();

        $applied = $this->matcherApplyOf($album) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertSame(
            [[$this->onlyArtistNamed('Tim McGraw')->getId()->toString(), false]],
            $this->queuedFills,
            'Tim McGraw was created with his ID; Post Malone held his already.',
        );
    }

    public function testASongApplyQueuesTheFillOfEachArtistItIdentifiedOnceItCommitted(): void
    {
        $album = $this->addAlbum();
        [$song] = $this->addSongsOf($album, self::release(self::releaseId(1)));
        $postMalone = $this->catalog->artist('Post Malone');
        $this->catalog->creditSong($song, $postMalone, ArtistRole::Primary);
        $recording = new MusicBrainzRecording('5ec0bd00-1111-4000-8000-000000000001', 'Wrong Ones', 100, 150_000, null, self::credit('Post Malone', 'Tim McGraw'), []);
        $this->musicBrainz->recordings[$recording->id] = $recording;
        $comparison = $this->compare(MatchItemType::Song, $song->getPublicId()->toString(), $recording->id);

        ApplyMatchCommand::manual(MatchItemType::Song, $song->getPublicId()->toString(), $recording->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertSame(
            [[$postMalone->getId()->toString(), false], [$this->onlyArtistNamed('Tim McGraw')->getId()->toString(), false]],
            $this->queuedFills,
        );
    }

    public function testAnApplyWhoseTransactionFailsQueuesNoFill(): void
    {
        $album = $this->addAlbum();
        [$release] = $this->longBed($album);
        $this->mergeFailure = new \RuntimeException('The merge failed after the credits were written.');

        try {
            ApplyMatchCommand::automatic($album->getId(), $this->candidate($album, $release)) |> $this->apply(...);
            self::fail('The failed merge fails the apply.');
        } catch (\RuntimeException $failure) {
            self::assertSame($this->mergeFailure, $failure);
        }

        self::assertContains('resolve', $this->calls, 'The apply had resolved its artists when it failed.');
        self::assertSame([], $this->queuedFills);
        self::assertSame([], $this->fillMarks->queued);
    }

    public function testAFillThatCannotBeQueuedIsLoggedWithTheArtistsAndTheApplyAnswersAsItDoesWithoutIt(): void
    {
        $this->fillFailure = new \RuntimeException('The transport cannot be reached.');
        $album = $this->addAlbum();
        [$release, $songs, $postMalone] = $this->longBed($album);
        $comparison = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id);

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertSame([$album->getPublicId()->toString(), count($songs)], [$applied->publicId, $applied->songsChanged]);
        $logged = $this->logged['Could not queue the MusicBrainz fill of {count} artist(s); a later metadata sync from the admin panel queues them.'] ?? [];
        self::assertSame(15, $logged['count'] ?? null);
        self::assertContains($postMalone->getId()->toString(), $logged['artist_ids'] ?? []);
        self::assertSame($this->fillFailure, $logged['exception'] ?? null);
        self::assertArrayHasKey('Applied a MusicBrainz match', $this->logged, 'The apply reports itself as applied.');
    }

    public function testAnArtistApplyThatGivesTheArtistItsIdQueuesNoFill(): void
    {
        $headhunterz = $this->catalog->artist('Headhunterz');
        $this->musicBrainz->artists[self::HEADHUNTERZ_ID] = self::headhunterz();
        $comparison = $this->compare(MatchItemType::Artist, $headhunterz->getPublicId()->toString(), self::HEADHUNTERZ_ID);

        $applied = ApplyMatchCommand::manual(MatchItemType::Artist, $headhunterz->getPublicId()->toString(), self::HEADHUNTERZ_ID, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertTrue($applied->isApplied());
        self::assertSame(self::HEADHUNTERZ_ID, $headhunterz->getMbid());
        self::assertSame([], $this->queuedFills, 'The admin chose the artist\'s fields.');
    }

    private function onlyArtistNamed(string $name): Artist
    {
        $artists = $this->artistsNamed($name);
        self::assertCount(1, $artists);

        return $artists[0];
    }

    /**
     * Applies the release to Long Bed by hand, with the comparison's default ticks, while an import
     * adds the song of the release's last track and, with automatic matching on, asks for the
     * album: the import commits after the apply read the album and before the apply holds the
     * album's row.
     *
     * @param bool $releaseTicked false when the admin unticked the release ID, which leaves the album without a release
     *
     * @return array{Album, Song, MatchApplyResult} the album, the imported song and the apply's result
     */
    private function applyByHandBesideAnImport(bool $releaseTicked = true): array
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releases[$release->id] = $release;
        $compared = $this->addSongsOf($album, $release, missing: ['2-9']);
        $selection = $this->compare(MatchItemType::Album, $album->getPublicId()->toString(), $release->id) |> MatchSelections::defaults(...);
        if (!$releaseTicked) {
            $selection = new MatchSelection(
                array_filter($selection->fields, static fn (SeenField $field): bool => $field->field !== MatchField::ReleaseMbid) |> array_values(...),
                $selection->credits,
                $selection->artistActions,
                $selection->keptArtists,
            );
        }
        $imported = null;
        $this->whileWaitingForTheAlbum = function () use ($album, $release, $compared, &$imported): void {
            [$imported] = $this->addSongsOf($album, $release, missing: array_map(self::placeOf(...), $compared));
            if ($this->automaticMatching) {
                $this->states->request($album->getId(), AlbumMatchSource::Ingest, new \DateTimeImmutable('2026-11-14 12:04:00'));
            }
        };

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $album->getPublicId()->toString(), $release->id, $selection) |> $this->apply(...);

        return [$album, $imported ?? throw new \LogicException('The import did not run.'), $applied];
    }

    /**
     * Long Bed with its first fourteen songs credited to a "Post Malone, <guest>" artist each and the
     * rest to an ID-less Post Malone, who is also the album's artist.
     *
     * @return array{MusicBrainzRelease, list<Song>, Artist, list<Artist>}
     */
    private function longBed(Album $album): array
    {
        $changes = [];
        foreach (self::GUESTS as $position => $guest) {
            $changes['1-' . $position] = ['artistCredit' => self::credit(self::ALBUM_ARTIST, $guest)];
        }
        $release = self::release(self::releaseId(1), changes: $changes);
        $this->musicBrainz->releases[$release->id] = $release;
        $songs = $this->addSongsOf($album, $release);
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $this->catalog->creditAlbum($album, $postMalone, ArtistRole::Primary);
        $duetArtists = [];
        foreach ($songs as $song) {
            $guest = self::guestOf($song);
            if ($guest === null) {
                $this->catalog->creditSong($song, $postMalone, ArtistRole::Primary);
                continue;
            }
            $duetArtists[] = $duetArtist = $this->catalog->artist(self::ALBUM_ARTIST . ', ' . $guest);
            $this->catalog->creditSong($song, $duetArtist, ArtistRole::Primary);
        }

        return [$release, $songs, $postMalone, $duetArtists];
    }

    /**
     * Long Bed as an apply left it an hour ago, holding the release and every song compared, and
     * credited since by hand to "Austin Post", who holds no other credit. The song of the release's
     * last track arrived after that apply, credited as tagged to a "Post Malone, Tim McGraw" artist
     * of its own; the release credits that track to Post Malone featuring Tim McGraw.
     *
     * @return array{Album, Song, Artist, Artist} the album, the new song, Austin Post and the new song's artist
     */
    private function matchedLongBedWithANewDuet(): array
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1), changes: ['2-9' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Tim McGraw')]]);
        $this->musicBrainz->releases[$release->id] = $release;
        $compared = $this->addSongsOf($album, $release, missing: ['2-9']);
        $this->matchWith($album, $release);
        $this->catalog->artist(self::ALBUM_ARTIST, self::artistId(self::ALBUM_ARTIST));
        $austinPost = $this->catalog->artist('Austin Post');
        $this->catalog->creditAlbum($album, $austinPost, ArtistRole::Primary);
        [$new] = $this->addSongsOf($album, $release, missing: array_map(self::placeOf(...), $compared));
        $duetArtist = $this->catalog->artist(self::ALBUM_ARTIST . ', Tim McGraw');
        $this->catalog->creditSong($new, $duetArtist, ArtistRole::Primary);

        return [$album, $new, $austinPost, $duetArtist];
    }

    /** The album as an apply of the release left it an hour before the test's clock: it holds the release, and every song it has was compared. */
    private function matchWith(Album $album, MusicBrainzRelease $release): void
    {
        $matchedAt = new \DateTimeImmutable('2026-11-14 11:00:00');
        $album->recordReleaseMatch(MusicbrainzId::fromString($release->id) ?? throw new \LogicException(), $matchedAt);
        foreach ($this->songs as $song) {
            if ($song->getAlbumId()->equals($album->getId())) {
                $song->markMatchChecked($matchedAt);
            }
        }
    }

    private function countryRelease(): MusicBrainzRelease
    {
        $release = self::releaseWith(
            self::release(self::releaseId(1)),
            date: '2024-03-01',
            releaseGroup: new MusicBrainzReleaseGroup('a1b0f000-0000-4000-8000-000000000001', self::ALBUM_TITLE, 'Album', '2024-03-01', ['Country']),
        );
        $this->musicBrainz->releases[$release->id] = $release;

        return $release;
    }

    private static function guestOf(Song $song): ?string
    {
        return $song->getDisc() === 1 ? self::GUESTS[$song->getTrack()] ?? null : null;
    }

    private static function headhunterz(): MusicBrainzArtist
    {
        return new MusicBrainzArtist(self::HEADHUNTERZ_ID, 'Headhunterz', 0, 'Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null, false);
    }

    /** The release as the matcher would decide on it: scored against the album as it is now. */
    private function candidate(Album $album, MusicBrainzRelease $release): MatchCandidate
    {
        $scored = $this->scorer()->scoreRelease($release, $this->snapshotsOverPorts()->ofAlbum($album)->localAlbum());

        return new MatchCandidate($release, $scored->score, $scored->pairs, [$release]);
    }

    /**
     * The apply the album match queue's matcher dispatches for the album as it is now. For a matched
     * album the matcher looks up the stored release and compares it with the songs no match has
     * compared yet.
     */
    private function matcherApplyOf(Album $album): ApplyMatchCommand
    {
        $dispatched = null;
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $command) use (&$dispatched): Envelope {
            $dispatched = $command;

            return new Envelope($command, [new HandledStamp(MatchApplyResult::notApplied(MatchApplyOutcome::Stale, null, ''), 'apply')]);
        });
        new AutomaticAlbumMatcher($this->albumPort(), $this->snapshotsOverPorts(), $this->lookup, new AlbumReleaseSearch($this->lookup), $this->scorer(), new MatchConfidence(), $bus)
            ->match($album->getId());

        return $dispatched instanceof ApplyMatchCommand ? $dispatched : throw new \LogicException('The matcher applied nothing.');
    }

    /** The song's tagged place, as addSongsOf() names a track. */
    private static function placeOf(Song $song): string
    {
        return $song->getDisc() . '-' . $song->getTrack();
    }

    private function compare(MatchItemType $type, string $publicId, string $candidateId): MatchComparison
    {
        $snapshots = $this->snapshotsOverPorts();

        return new CompareMatchHandler($this->musicBrainz, $this->scorer(), $snapshots, new MatchComparisonBuilder($this->catalog, $this->catalogArtists()))(
            new CompareMatchQuery($type, $publicId, $candidateId),
        );
    }

    private function apply(ApplyMatchCommand $command): MatchApplyResult
    {
        return $this->applyHandler()($command);
    }

    /** @param Closure(): mixed $apply */
    private function expectConflict(Closure $apply): ConflictException
    {
        try {
            $apply();
        } catch (ConflictException $conflict) {
            return $conflict;
        }

        self::fail('The apply did not answer with a conflict.');
    }

    /** @param Closure(): mixed $apply */
    private function expectNotFound(Closure $apply): NotFoundException
    {
        try {
            $apply();
        } catch (NotFoundException $notFound) {
            return $notFound;
        }

        self::fail('The apply did not answer that something was not found.');
    }

    /** @return list<array{string, string|null, string}> artist name, role and credited name of each credit of the song */
    private function creditsOf(Song $song): array
    {
        return array_map(
            static fn (CatalogCredit $credit): array => [$credit->artistName, $credit->role, $credit->creditedName],
            $this->catalog->songCredits[$song->getId()->toString()] ?? [],
        );
    }

    /** @return list<array{string, string|null, string}> artist name, role and credited name of each credit of the album */
    private function albumCreditsOf(Album $album): array
    {
        return array_map(
            static fn (CatalogCredit $credit): array => [$credit->artistName, $credit->role, $credit->creditedName],
            $this->catalog->albumCredits[$album->getId()->toString()] ?? [],
        );
    }

    /** @return list<Artist> */
    private function artistsNamed(string $name): array
    {
        return array_filter($this->catalog->artists, static fn (Artist $artist): bool => $artist->getName() === $name) |> array_values(...);
    }

    private function catalogArtist(Uuid $id): ?Artist
    {
        return array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getId()->equals($id));
    }

    private function applyHandler(): ApplyMatchHandler
    {
        $artists = $this->catalogArtists();
        $images = $this->images();
        $storage = $this->storage();

        return new ApplyMatchHandler(
            musicBrainz: $this->lookup,
            scorer: $this->scorer(),
            snapshots: $this->snapshotsOverPorts(),
            comparisons: new MatchComparisonBuilder($this->catalog, $artists),
            covers: new MatchCovers($this->lookup, $storage, new NullLogger(), new MimeDetector()),
            albums: $this->albumPort(),
            songs: $this->songPort(),
            artists: $artists,
            artistWriter: new ArtistMatchWriter($artists),
            genres: $this->genrePort(),
            artistIdentities: $this->resolver(),
            merges: $this->mergePort(),
            matchStates: $this->matchStates(),
            matchRequests: $this->matchRequests(),
            artistSyncs: $this->artistSyncs(),
            images: $images,
            discarder: new CoverImageDiscarder($images, $storage, new NullLogger()),
            transaction: $this->transaction(),
            clock: new MockClock(self::NOW),
            logger: $this->logger(),
        );
    }

    /** Keeps the context of each entry the apply logs, by its message. */
    private function logger(): LoggerInterface
    {
        $record = function (string $message, array $context): void {
            $this->logged[$message] = $context;
        };

        return new class ($record) extends AbstractLogger {
            public function __construct(private readonly Closure $record)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message, $context);
            }
        };
    }

    private function snapshotsOverPorts(): MatchSnapshots
    {
        return new MatchSnapshots($this->albumPort(), $this->songPort(), $this->catalogArtists(), $this->catalog);
    }

    private function albumPort(): AlbumPortInterface
    {
        $byId = fn (Uuid $id): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getId()->equals($id));
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getPublicId()->equals($id)),
        );
        $albums->method('findByUuid')->willReturnCallback($byId);
        $albums->method('findFreshByUuid')->willReturnCallback($byId);
        $albums->method('lockInIdOrder')->willReturnCallback(function (): void {
            $this->calls[] = 'lock album';
            if ($this->whileWaitingForTheAlbum !== null) {
                ($this->whileWaitingForTheAlbum)();
            }
        });
        $albums->method('replacePerformerCredits')->willReturnCallback(
            fn (Uuid $albumId, array $credits): bool => $this->replaceCredits($this->catalog->albumCredits, $albumId, $credits),
        );
        $albums->method('replaceGenres')->willReturnCallback(function (Uuid $albumId, array $genreIds): bool {
            $this->catalog->albumGenres[$albumId->toString()] = array_map(fn (Uuid $id): string => $this->genreNames[$id->toString()], $genreIds);

            return true;
        });

        return $albums;
    }

    private function songPort(): SongPortInterface
    {
        $byId = fn (Uuid $id): ?Song => array_find($this->songs, static fn (Song $song): bool => $song->getId()->equals($id));
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Song => array_find($this->songs, static fn (Song $song): bool => $song->getPublicId()->equals($id)),
        );
        $songs->method('findByUuid')->willReturnCallback($byId);
        $songs->method('findFreshByUuid')->willReturnCallback($byId);
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(
            fn (Uuid $albumId): array => array_filter($this->songs, static fn (Song $song): bool => $song->getAlbumId()->equals($albumId)) |> array_values(...),
        );
        $songs->method('lockImportIdentities')->willReturnCallback(function (): void {
            $this->calls[] = 'lock identities';
        });
        $songs->method('lockInIdOrder')->willReturnCallback(function (): void {
            $this->calls[] = 'lock songs';
        });
        $songs->method('replacePerformerCredits')->willReturnCallback(
            fn (Uuid $songId, array $credits): bool => $this->replaceCredits($this->catalog->songCredits, $songId, $credits),
        );

        return $songs;
    }

    private function catalogArtists(): ArtistPortInterface
    {
        $byId = fn (Uuid $id): ?Artist => $this->catalogArtist($id);
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Artist => array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getPublicId()->equals($id)),
        );
        $artists->method('findByMbid')->willReturnCallback(
            fn (?MusicbrainzId $mbid): ?Artist => $mbid === null ? null : array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getMbid() === $mbid->toString()),
        );
        $artists->method('findByUuid')->willReturnCallback($byId);
        $artists->method('findFreshByUuid')->willReturnCallback($byId);
        $artists->method('findByUuids')->willReturnCallback(function (array $ids): array {
            $found = [];
            foreach ($ids as $id) {
                $artist = $this->catalogArtist($id);
                if ($artist !== null) {
                    $found[$id->toString()] = $artist;
                }
            }

            return $found;
        });

        return $artists;
    }

    private function genrePort(): GenrePortInterface
    {
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findOrCreateForImport')->willReturnCallback(function (array $names): array {
            $ids = [];
            foreach ($names as $name) {
                $id = array_search($name, $this->genreNames, true);
                $id = $id === false ? new Uuid()->toString() : $id;
                $this->genreNames[$id] = $name;
                $ids[$name] = Uuid::fromString($id);
            }

            return $ids;
        });

        return $genres;
    }

    /**
     * Resolves identities as the import does, through the catalog fake's prediction, creating
     * artists and giving IDs as predicted, and reports each artist it created with an ID or gave one.
     */
    private function resolver(): ArtistIdentityResolverInterface
    {
        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolveReporting')->willReturnCallback(function (array $identities): ArtistResolution {
            $this->calls[] = 'resolve';
            $predicted = $this->catalog->predictArtists($identities);
            $created = [];
            $resolved = [];
            $identified = [];
            foreach ($identities as $identity) {
                $artist = $predicted[$identity->key()];
                $mbid = $identity->musicbrainzId?->toString();
                if ($artist->id === null) {
                    if (!isset($created[spl_object_id($artist)])) {
                        $made = $created[spl_object_id($artist)] = $this->catalog->artist($identity->name, $mbid);
                        if ($mbid !== null) {
                            $identified[] = new IdentifiedArtist($made->getId(), $mbid);
                        }
                    }
                    $resolved[$identity->key()] = $created[spl_object_id($artist)]->getId();
                    continue;
                }
                $stored = $this->catalogArtist($artist->id);
                if ($artist->takesId && $mbid !== null && $stored !== null && $stored->getMbid() === null) {
                    $stored->updateExternalIds(mbid: $mbid);
                    $identified[] = new IdentifiedArtist($artist->id, $mbid);
                }
                $resolved[$identity->key()] = $artist->id;
            }

            return new ArtistResolution($resolved, $identified);
        });

        return $resolver;
    }

    /** Merges and removes artists in the catalog fake; an artist holds a credit when the fake lists one for it. */
    private function mergePort(): ArtistMergePortInterface
    {
        $merges = $this->createStub(ArtistMergePortInterface::class);
        $merges->method('lock')->willReturnCallback(function (array $artistIds): array {
            $this->calls[] = 'lock artists';
            $credited = [];
            foreach ($artistIds as $id) {
                if ($this->catalogArtist($id) !== null) {
                    $credited[$id->toString()] = $this->isCredited($id);
                }
            }

            return $credited;
        });
        $merges->method('merge')->willReturnCallback(function (Uuid $sourceId, Uuid $targetId): ArtistRemoval {
            if ($this->mergeFailure !== null) {
                throw $this->mergeFailure;
            }
            $source = $this->catalogArtist($sourceId) ?? throw new \LogicException('No source.');
            $target = $this->catalogArtist($targetId) ?? throw new \LogicException('No target.');
            $this->calls[] = 'merge ' . $source->getName() . ' into ' . $target->getName();
            $this->repointCredits($this->catalog->songCredits, $source, $target);
            $this->repointCredits($this->catalog->albumCredits, $source, $target);
            $this->forget($source);
            $cover = $source->getCoverImageId();
            if ($cover !== null && $target->getCoverImageId() === null) {
                $target->setCoverImage($cover);

                return new ArtistRemoval();
            }

            return new ArtistRemoval($cover === null ? [] : [$cover]);
        });
        $merges->method('remove')->willReturnCallback(function (Uuid $artistId): ArtistRemoval {
            $artist = $this->catalogArtist($artistId) ?? throw new \LogicException('No artist.');
            $this->calls[] = 'remove ' . $artist->getName();
            $this->forget($artist);

            return new ArtistRemoval();
        });

        return $merges;
    }

    private function matchStates(): AlbumMatchStateRepositoryInterface
    {
        $states = $this->createStub(AlbumMatchStateRepositoryInterface::class);
        $states->method('removeByAlbum')->willReturnCallback(function (Uuid $albumId): void {
            $this->calls[] = 'remove match state';
            $this->states->removeByAlbum($albumId);
        });

        return $states;
    }

    /**
     * The production match requester over the library and the match states held in memory, with
     * automatic matching as the test switched it; it counts the queue runs it asks for.
     */
    private function matchRequests(): AlbumMatchRequestInterface
    {
        if ($this->requestFailure !== null) {
            $failing = $this->createStub(AlbumMatchRequestInterface::class);
            $failing->method('requestAfterImport')->willThrowException($this->requestFailure);

            return $failing;
        }
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            if ($message instanceof DrainAlbumMatchesMessage) {
                $this->queuedRuns[] = array_find($stamps, static fn (object $stamp): bool => $stamp instanceof DelayStamp)?->getDelay();
            }

            return new Envelope($message, $stamps);
        });

        return new AlbumMatchRequester($this->states, $this->albumPort(), $this->songPort(), $this->settings(), $bus, new MockClock(self::NOW));
    }

    /**
     * The production artist fill requester over marks held in memory, with automatic matching as
     * the test switched it; it keeps each fill it queues and whether a transaction was open then.
     */
    private function artistSyncs(): ArtistSyncRequestInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            if ($this->fillFailure !== null) {
                throw $this->fillFailure;
            }
            self::assertInstanceOf(SyncArtistMessage::class, $message);
            $this->queuedFills[] = [$message->artistId->toString(), $this->inTransaction];

            return new Envelope($message, $stamps);
        });

        return new ArtistSyncRequester(new ArtistSyncQueue($this->fillMarks, $bus), $this->settings(), $this->logger());
    }

    /** Automatic matching, on or off as the test switched it. */
    private function settings(): SystemSettingsPortInterface
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(fn (string $key): bool => $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching);

        return $settings;
    }

    private function images(): ImagePortInterface
    {
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('save')->willReturnCallback(function (Image $image): void {
            $this->savedImages[$image->getId()->toString()] = $image;
        });
        $images->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?Image => $this->imageLookupFailure === null ? $this->savedImages[$id->toString()] ?? null : throw $this->imageLookupFailure,
        );
        $images->method('assignArtistOwner')->willReturnCallback(function (Uuid $imageId, Uuid $artistId): bool {
            $this->calls[] = 'assign owner ' . $imageId->toString() . ' to ' . $this->catalogArtist($artistId)?->getName();

            return true;
        });

        return $images;
    }

    private function storage(): StoragePortInterface
    {
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('storeFromBytes')->willReturnCallback(function (string $contents, string $path): StoredFile {
            $this->calls[] = 'store file';

            return new StoredFile($path, 'image/png', strlen($contents));
        });
        $storage->method('delete')->willReturnCallback(function (): void {
            $this->calls[] = 'delete file';
        });

        return $storage;
    }

    /**
     * Runs the operation; when it fails, the images it saved are rolled back with it. A commit
     * failure keeps them, as a commit the server finished before the connection failed.
     */
    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            if ($this->transactionFailure !== null) {
                throw $this->transactionFailure;
            }
            $images = $this->savedImages;
            $this->inTransaction = true;
            try {
                $result = $operation();
            } catch (Throwable $failure) {
                $this->savedImages = $images;
                throw $failure;
            } finally {
                $this->inTransaction = false;
            }

            return $this->commitFailure === null ? $result : throw $this->commitFailure;
        });

        return $transaction;
    }

    /**
     * Replaces the primary and featured credits of one song or album in the catalog fake.
     *
     * @param array<string, list<CatalogCredit>>                                  $store
     * @param list<array{artistId: Uuid, role: ArtistRole, creditedName: string}> $credits
     */
    private function replaceCredits(array &$store, Uuid $ownerId, array $credits): bool
    {
        $before = $store[$ownerId->toString()] ?? [];
        $store[$ownerId->toString()] = [
            ...array_map(function (array $credit): CatalogCredit {
                $artist = $this->catalogArtist($credit['artistId']) ?? throw new \LogicException('No credited artist.');

                return new CatalogCredit($artist->getId(), $artist->getPublicId()->toString(), $artist->getName(), $credit['role']->value, $credit['creditedName']);
            }, $credits),
            ...array_filter($before, static fn (CatalogCredit $credit): bool => !$credit->isPerformerCredit()),
        ];

        return $store[$ownerId->toString()] != $before;
    }

    /** @param array<string, list<CatalogCredit>> $store */
    private function repointCredits(array &$store, Artist $source, Artist $target): void
    {
        foreach ($store as $owner => $credits) {
            $moved = [];
            foreach ($credits as $credit) {
                $credit = $credit->artistId->equals($source->getId())
                    ? new CatalogCredit($target->getId(), $target->getPublicId()->toString(), $target->getName(), $credit->role, $credit->creditedName)
                    : $credit;
                $moved[$credit->artistId->toString() . ' ' . $credit->role] ??= $credit;
            }
            $store[$owner] = array_values($moved);
        }
    }

    private function forget(Artist $artist): void
    {
        $this->catalog->artists = array_filter($this->catalog->artists, static fn (Artist $kept): bool => $kept !== $artist) |> array_values(...);
        foreach ([&$this->catalog->songCredits, &$this->catalog->albumCredits] as &$store) {
            foreach ($store as $owner => $credits) {
                $store[$owner] = array_filter($credits, static fn (CatalogCredit $credit): bool => !$credit->artistId->equals($artist->getId())) |> array_values(...);
            }
        }
    }

    private function isCredited(Uuid $artistId): bool
    {
        return in_array($artistId->toString(), $this->catalog->creditedElsewhere, true)
            || array_any(
                [...$this->catalog->songCredits, ...$this->catalog->albumCredits],
                static fn (array $credits): bool => array_any($credits, static fn (CatalogCredit $credit): bool => $credit->artistId->equals($artistId)),
            );
    }
}
