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
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairs;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
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
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
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

    /** A PNG of one pixel. */
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private CoverServingLookup $lookup;

    /** @var list<string> what the apply asked of the ports, in order */
    private array $calls = [];

    /** @var array<string, string> genre names by genre ID */
    private array $genreNames = [];

    /** @var array<string, Image> images saved, by ID */
    private array $savedImages = [];

    /** Runs when the apply locks the album's row, as a writer that committed while the apply waited for it. */
    private ?Closure $whileWaitingForTheAlbum = null;

    /** Fails the apply's transaction instead of running it. */
    private ?Throwable $transactionFailure = null;

    protected function setUp(): void
    {
        $this->setUpLibrary();
        $this->lookup = new CoverServingLookup($this->musicBrainz);
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

    public function testAMatchWithoutAnAdminReportsACoverArchiveOutageAndWritesNothing(): void
    {
        $album = $this->addAlbum();
        $release = self::releaseWith(self::release(self::releaseId(1)), hasFrontCover: true);
        $this->addSongsOf($album, $release);
        $this->lookup->covers[$release->id] = new MusicBrainzUnavailable('HTTP 503');

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

    /** @return list<array{string, string|null, string}> artist name, role and credited name of each credit of the song */
    private function creditsOf(Song $song): array
    {
        return array_map(
            static fn (CatalogCredit $credit): array => [$credit->artistName, $credit->role, $credit->creditedName],
            $this->catalog->songCredits[$song->getId()->toString()] ?? [],
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
            covers: new MatchCovers($this->lookup, $storage, new NullLogger()),
            albums: $this->albumPort(),
            songs: $this->songPort(),
            artists: $artists,
            genres: $this->genrePort(),
            artistIdentities: $this->resolver(),
            merges: $this->mergePort(),
            matchStates: $this->matchStates(),
            images: $images,
            discarder: new CoverImageDiscarder($images, $storage, new NullLogger()),
            transaction: $this->transaction(),
            clock: new MockClock('2026-11-14 12:00:00'),
            logger: new NullLogger(),
        );
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

    /** Resolves identities as the import does, through the catalog fake's prediction, creating artists and giving IDs as predicted. */
    private function resolver(): ArtistIdentityResolverInterface
    {
        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (array $identities): array {
            $this->calls[] = 'resolve';
            $predicted = $this->catalog->predictArtists($identities);
            $created = [];
            $resolved = [];
            foreach ($identities as $identity) {
                $artist = $predicted[$identity->key()];
                if ($artist->id === null) {
                    $made = $created[spl_object_id($artist)] ??= $this->catalog->artist($identity->name, $identity->musicbrainzId?->toString());
                    $resolved[$identity->key()] = $made->getId();
                    continue;
                }
                if ($artist->takesId) {
                    $this->catalogArtist($artist->id)?->updateExternalIds(mbid: $identity->musicbrainzId?->toString());
                }
                $resolved[$identity->key()] = $artist->id;
            }

            return $resolved;
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
        $states->method('removeByAlbum')->willReturnCallback(function (): void {
            $this->calls[] = 'remove match state';
        });

        return $states;
    }

    private function images(): ImagePortInterface
    {
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('save')->willReturnCallback(function (Image $image): void {
            $this->savedImages[$image->getId()->toString()] = $image;
        });
        $images->method('assignArtistOwner')->willReturnCallback(function (Uuid $imageId, Uuid $artistId): bool {
            $this->calls[] = 'assign owner ' . $imageId->toString() . ' to ' . $this->catalogArtist($artistId)?->getName();

            return true;
        });

        return $images;
    }

    private function storage(): StoragePortInterface
    {
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('storeFromBytes')->willReturnCallback(
            static fn (string $contents, string $path): StoredFile => new StoredFile($path, 'image/png', strlen($contents)),
        );
        $storage->method('delete')->willReturnCallback(function (): void {
            $this->calls[] = 'delete file';
        });

        return $storage;
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(fn (callable $operation): mixed => $this->transactionFailure === null ? $operation() : throw $this->transactionFailure);

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
