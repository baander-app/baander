<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\ApplyMatchHandler;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcherInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcomeKind;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMediumSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Tests\Unit\Catalog\Application\CommandHandler\MusicBrainzMatch\CoverServingLookup;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch\MusicBrainzReleaseFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Applies MusicBrainz matches through the container's handler against the migrated PostgreSQL
 * schema, with MusicBrainz answered by a fake: credits, artists, IDs, provenance, genres and covers
 * land together, a failure in the middle leaves everything as it was, a cover the Cover Art Archive
 * refuses is left out of an automatic match, and an artist that takes an ID another artist holds
 * merges into it with its cover. Cover files live in a storage directory of the test's own.
 */
final class MusicBrainzMatchApplyTest extends KernelTestCase
{
    use MusicBrainzReleaseFixtures;

    private const string HEADHUNTERZ_ID = '6c1e0000-0000-4000-8000-000000000001';

    /** A PNG of one pixel. */
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private ?string $schema = null;
    private string $searchPath;
    private string $storageRoot;
    private FakeMusicBrainzLookup $musicBrainz;
    private CoverServingLookup $lookup;
    private string $libraryId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->isolateSchema();
        $this->storageRoot = sys_get_temp_dir() . '/baander-match-apply-' . (random_bytes(4) |> bin2hex(...));
        static::getContainer()->set(StoragePortInterface::class, new FlysystemStorage($this->storageRoot));
        $this->musicBrainz = new FakeMusicBrainzLookup();
        $this->lookup = new CoverServingLookup($this->musicBrainz);
        static::getContainer()->set(MusicBrainzLookupInterface::class, $this->lookup);
        $this->libraryId = $this->storeLibrary();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->storageRoot);
        if ($this->schema !== null) {
            $connection = $this->connection();
            $connection->executeStatement('SET search_path TO ' . $this->searchPath);
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
        }
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testAConfidentMatchCreditsEachDuetToPostMaloneAndItsGuestMergesTheCombinedArtistsAndKeepsTheLyrics(): void
    {
        $longBed = $this->storeLongBed();
        $this->lookup->covers[$longBed['release']->id] = new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'image/png');

        $result = ApplyMatchCommand::automatic(Uuid::fromString($longBed['album']), $this->candidate($longBed['albumPublicId'], $longBed['release'])) |> $this->apply(...);

        self::assertTrue($result->isApplied());
        [$lukeCombsSong, $morganWallenSong, $soloSong] = $longBed['songs'];
        $postMalone = $longBed['postMalone'];
        self::assertSame(
            [[$postMalone, 'primary', 'Post Malone, Luke Combs'], [$this->artistNamed('Luke Combs'), 'featured', 'Luke Combs']],
            $this->songCredits($lukeCombsSong),
            'The duet keeps showing its tagged text, which lyrics lookups send; its guest is featured.',
        );
        self::assertSame(
            [[$postMalone, 'primary', 'Post Malone, Morgan Wallen'], [$this->artistNamed('Morgan Wallen'), 'featured', 'Morgan Wallen']],
            $this->songCredits($morganWallenSong),
        );
        self::assertSame([[$postMalone, 'primary', 'Post Malone']], $this->songCredits($soloSong));
        self::assertSame([], $this->artistsNamedLike('Post Malone, %'), 'The combined artists merged into Post Malone.');
        self::assertSame([$postMalone], $this->artistsNamedLike('Post Malone'), 'The ID-less Post Malone took on the ID.');
        self::assertSame(self::artistId('Post Malone'), $this->connection()->fetchOne('SELECT mbid FROM artists WHERE id = ?', [$postMalone]));
        foreach ($longBed['songs'] as $position => $song) {
            $row = $this->connection()->fetchAssociative('SELECT mbid, matched_fields, match_checked_at FROM songs WHERE id = ?', [$song]);
            self::assertSame(self::recordingId(1, $position + 1), $row['mbid'] ?? null);
            self::assertContains('credits', json_decode((string) ($row['matched_fields'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR));
            self::assertNotNull($row['match_checked_at'] ?? null, 'The match compared the song.');
        }
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT count(*) FROM lyrics'), 'Every song keeps its lyrics.');
        $album = $this->connection()->fetchAssociative('SELECT release_mbid, matched_at, cover_image_id::text AS cover FROM albums WHERE id = ?', [$longBed['album']]);
        self::assertSame($longBed['release']->id, $album['release_mbid'] ?? null);
        self::assertNotNull($album['matched_at'] ?? null);
        self::assertSame(
            ['album', $longBed['album']],
            $this->connection()->fetchNumeric('SELECT imageable_type, album_id::text FROM images WHERE id = ?', [$album['cover'] ?? '']),
            'The missing cover was stored as the album\'s.',
        );
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM album_match_states'), 'The queue removes the request it claimed itself.');
    }

    /**
     * The queue's matcher searches the album, finds its release confident and applies it, and the
     * Cover Art Archive answers the download of the release's front cover with 403.
     */
    public function testAConfidentAlbumWhoseCoverTheCoverArtArchiveRefusesIsMatchedWithItsIdsAndCreditsAndWithoutACover(): void
    {
        $longBed = $this->storeLongBed();
        $release = $longBed['release'];
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;
        $this->lookup->covers[$release->id] = new MusicBrainzRejected(403);

        $outcome = static::getContainer()->get(AutomaticAlbumMatcherInterface::class)->match(Uuid::fromString($longBed['album']));

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind, 'The refused cover is no outage: the queue goes on.');
        self::assertContains('fetchFrontCover:' . $release->id, $this->musicBrainz->calls);
        $album = $this->connection()->fetchAssociative('SELECT release_mbid, matched_at, cover_image_id::text AS cover FROM albums WHERE id = ?', [$longBed['album']]);
        self::assertSame($release->id, $album['release_mbid'] ?? null);
        self::assertNotNull($album['matched_at'] ?? null);
        self::assertNull($album['cover'] ?? null);
        [$lukeCombsSong] = $longBed['songs'];
        self::assertSame(
            [[$longBed['postMalone'], 'primary', 'Post Malone, Luke Combs'], [$this->artistNamed('Luke Combs'), 'featured', 'Luke Combs']],
            $this->songCredits($lukeCombsSong),
        );
        self::assertSame(
            [self::recordingId(1, 1), self::recordingId(1, 2), self::recordingId(1, 3)],
            array_map(fn (string $song): mixed => $this->connection()->fetchOne('SELECT mbid FROM songs WHERE id = ?', [$song]), $longBed['songs']),
        );
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM images'), 'No image is stored.');
        self::assertSame([], $this->storedFiles(), 'No cover file is left.');
    }

    public function testWithTheYearUntickedAndTheGenreTickedTheGenreIsFiledAndTheYearKept(): void
    {
        [$album, $albumPublicId] = $this->storeAlbum(year: 2023);
        $release = self::releaseWith(
            self::release(self::releaseId(1), discs: [2], date: '2024-03-01'),
            releaseGroup: new MusicBrainzReleaseGroup('a1b0f000-0000-4000-8000-000000000001', self::ALBUM_TITLE, 'Album', '2024-03-01', ['Country']),
        );
        foreach ([1, 2] as $position) {
            $this->storeSong($album, $release, $position, []);
        }
        $this->storeMatchState($album);

        $comparison = $this->compare(MatchItemType::Album, $albumPublicId, $release);
        self::assertFalse($comparison->field(MatchField::Year)?->ticked);
        ApplyMatchCommand::manual(MatchItemType::Album, $albumPublicId, $release->id, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertSame(2023, (int) $this->connection()->fetchOne('SELECT year FROM albums WHERE id = ?', [$album]));
        self::assertSame(
            ['Country'],
            $this->connection()->fetchFirstColumn('SELECT g.name FROM genre_album ga JOIN genres g ON g.id = ga.genre_id WHERE ga.album_id = ?', [$album]),
        );
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM album_match_states'), 'A manual apply takes the album off the review list.');
    }

    public function testAFailureInTheMiddleLeavesEverySongCreditAndArtistAsBefore(): void
    {
        $longBed = $this->storeLongBed();
        // The release's country, which the apply writes after the artists and credits.
        $this->connection()->executeStatement("ALTER TABLE albums ADD CONSTRAINT chk_albums_test_country CHECK (country IS DISTINCT FROM 'XW')");
        $artists = $this->artistRows();
        $credits = array_map($this->songCredits(...), $longBed['songs']);

        try {
            ApplyMatchCommand::automatic(Uuid::fromString($longBed['album']), $this->candidate($longBed['albumPublicId'], $longBed['release'])) |> $this->apply(...);
            self::fail('The apply did not fail on the check constraint.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }

        self::assertSame($artists, $this->artistRows(), 'No artist was created, merged or given an ID.');
        self::assertSame($credits, array_map($this->songCredits(...), $longBed['songs']));
        self::assertSame([null], $this->connection()->fetchFirstColumn('SELECT DISTINCT mbid FROM songs'));
        self::assertNull($this->connection()->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$longBed['album']]));
    }

    public function testASongMatchSetsTheSongsRecordingIdTitleAndCreditsAndLeavesItsAlbumAlone(): void
    {
        [$album, $albumPublicId] = $this->storeAlbum();
        $release = self::release(self::releaseId(1), discs: [1]);
        [$postMalone] = $this->storeArtist('Post Malone');
        [$song, $songPublicId] = $this->storeSong($album, $release, 1, [[$postMalone, 'primary', 'Post Malone']]);
        $recording = new MusicBrainzRecording('5ec0bd00-1111-4000-8000-000000000001', 'Wrong Ones', 100, 150_000, null, self::credit('Post Malone', 'Tim McGraw'), []);
        $this->musicBrainz->recordings[$recording->id] = $recording;

        $comparison = $this->compare(MatchItemType::Song, $songPublicId, null, $recording->id);
        ApplyMatchCommand::manual(MatchItemType::Song, $songPublicId, $recording->id, MatchSelections::defaults($comparison, [MatchField::Title])) |> $this->apply(...);

        self::assertSame(
            ['mbid' => $recording->id, 'title' => 'Wrong Ones', 'album_id' => $album],
            $this->connection()->fetchAssociative('SELECT mbid, title, album_id::text AS album_id FROM songs WHERE id = ?', [$song]),
        );
        self::assertSame([[$postMalone, 'primary', 'Post Malone'], [$this->artistNamed('Tim McGraw'), 'featured', 'Tim McGraw']], $this->songCredits($song));
        self::assertNull($this->connection()->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$album]));
        self::assertSame($albumPublicId, $this->connection()->fetchOne('SELECT public_id FROM albums WHERE id = ?', [$album]));
    }

    public function testAnArtistTakingAnIdAnotherArtistHoldsMergesIntoItWhichTakesAndOwnsItsCover(): void
    {
        [$headHunterz, $headHunterzPublicId] = $this->storeArtist('HeadHunterz');
        $cover = $this->storeCover($headHunterz);
        [$headhunterz] = $this->storeArtist('Headhunterz', self::HEADHUNTERZ_ID);
        [$album] = $this->storeAlbum();
        [$song] = $this->storeSong($album, self::release(self::releaseId(1), discs: [1]), 1, [[$headHunterz, 'primary', 'HeadHunterz']]);
        $this->musicBrainz->artists[self::HEADHUNTERZ_ID] = new MusicBrainzArtist(self::HEADHUNTERZ_ID, 'Headhunterz', 0, 'Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null, false);

        $comparison = $this->compare(MatchItemType::Artist, $headHunterzPublicId, null, self::HEADHUNTERZ_ID);
        $result = ApplyMatchCommand::manual(MatchItemType::Artist, $headHunterzPublicId, self::HEADHUNTERZ_ID, MatchSelections::defaults($comparison)) |> $this->apply(...);

        self::assertSame([$headHunterzPublicId], $result->mergedArtists);
        self::assertSame([[$headhunterz, 'Headhunterz']], $this->artistRows());
        self::assertSame([[$headhunterz, 'primary', 'HeadHunterz']], $this->songCredits($song));
        self::assertSame($cover, $this->connection()->fetchOne('SELECT cover_image_id::text FROM artists WHERE id = ?', [$headhunterz]));
        self::assertSame(
            $headhunterz,
            $this->connection()->fetchOne('SELECT artist_id::text FROM images WHERE id = ?', [$cover]),
            'The cover names its new owner, so the image resource reports the artist it belongs to.',
        );
    }

    /**
     * The matcher read the album with one song; the second was imported before the apply took its
     * locks. The apply leaves the second unchecked, so the album is requested again and the next match
     * pairs it against the stored release, though it is older than the album's match time.
     */
    public function testASongImportedWhileAMatchRanIsRequestedAndPairedByTheNextMatch(): void
    {
        [$album, $albumPublicId] = $this->storeAlbum();
        $release = self::release(self::releaseId(1), discs: [2]);
        $this->musicBrainz->releases[$release->id] = $release;
        [$first] = $this->storeSong($album, $release, 1, []);
        $candidate = $this->candidate($albumPublicId, $release);
        [$imported] = $this->storeSong($album, $release, 2, []);

        $result = ApplyMatchCommand::automatic(Uuid::fromString($album), $candidate) |> $this->apply(...);

        self::assertTrue($result->isApplied());
        self::assertNotNull($this->connection()->fetchOne('SELECT match_checked_at FROM songs WHERE id = ?', [$first]));
        self::assertSame(
            ['mbid' => null, 'match_checked_at' => null],
            $this->connection()->fetchAssociative('SELECT mbid, match_checked_at FROM songs WHERE id = ?', [$imported]),
            'The match did not compare the imported song.',
        );

        static::getContainer()->get(AlbumMatchRequestInterface::class)->requestForAdmin(Uuid::fromString($album));
        self::assertSame('requested', $this->connection()->fetchOne('SELECT state FROM album_match_states WHERE album_id = ?', [$album]));
        $outcome = static::getContainer()->get(AutomaticAlbumMatcherInterface::class)->match(Uuid::fromString($album));

        self::assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        $row = $this->connection()->fetchAssociative('SELECT mbid, match_checked_at FROM songs WHERE id = ?', [$imported]);
        self::assertSame(self::recordingId(1, 2), $row['mbid'] ?? null);
        self::assertNotNull($row['match_checked_at'] ?? null);
    }

    /**
     * "F-1 Trillion: Long Bed" cut to three tracks: two duets, each credited to a "Post Malone,
     * <guest>" artist, and a song and the album credited to an ID-less Post Malone. Each song has
     * lyrics, and the album waits in the match queue.
     *
     * @return array{album: string, albumPublicId: string, release: MusicBrainzRelease, songs: list<string>, postMalone: string}
     */
    private function storeLongBed(): array
    {
        [$album, $albumPublicId] = $this->storeAlbum();
        $release = self::releaseWith(
            self::release(self::releaseId(1), discs: [3], changes: [
                '1-1' => ['artistCredit' => self::credit('Post Malone', 'Luke Combs')],
                '1-2' => ['artistCredit' => self::credit('Post Malone', 'Morgan Wallen')],
            ]),
            hasFrontCover: true,
        );
        [$postMalone] = $this->storeArtist('Post Malone');
        [$lukeCombs] = $this->storeArtist('Post Malone, Luke Combs');
        [$morganWallen] = $this->storeArtist('Post Malone, Morgan Wallen');
        $songs = [
            $this->storeSong($album, $release, 1, [[$lukeCombs, 'primary', 'Post Malone, Luke Combs']])[0],
            $this->storeSong($album, $release, 2, [[$morganWallen, 'primary', 'Post Malone, Morgan Wallen']])[0],
            $this->storeSong($album, $release, 3, [[$postMalone, 'primary', 'Post Malone']])[0],
        ];
        $this->connection()->executeStatement(
            "INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, 'primary', 'Post Malone')",
            [(new Uuid())->toString(), $postMalone, $album],
        );
        foreach ($songs as $song) {
            $this->connection()->executeStatement(
                "INSERT INTO lyrics (id, song_id, plain_lyrics, source, created_at, updated_at) VALUES (?, ?, 'Pour me a drink', 'lrclib', clock_timestamp(), clock_timestamp())",
                [(new Uuid())->toString(), $song],
            );
        }
        $this->connection()->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at) VALUES (?, 'requested', 'ingest', clock_timestamp())",
            [$album],
        );

        return ['album' => $album, 'albumPublicId' => $albumPublicId, 'release' => $release, 'songs' => $songs, 'postMalone' => $postMalone];
    }

    /** The release with the named values replaced, such as `hasFrontCover: true`. */
    private static function releaseWith(MusicBrainzRelease $release, mixed ...$changes): MusicBrainzRelease
    {
        return new MusicBrainzRelease(...[...get_object_vars($release), ...$changes]);
    }

    /** The release as a release search lists it. */
    private static function summary(MusicBrainzRelease $release): MusicBrainzReleaseSummary
    {
        return new MusicBrainzReleaseSummary(
            id: $release->id,
            title: $release->title,
            score: 100,
            artistCredit: $release->artistCredit,
            date: $release->date,
            country: $release->country,
            status: $release->status,
            disambiguation: $release->disambiguation,
            barcode: $release->barcode,
            labels: $release->labels,
            media: array_map(static fn (MusicBrainzMedium $medium): MusicBrainzMediumSummary => new MusicBrainzMediumSummary($medium->format, count($medium->tracks)), $release->media),
            trackCount: $release->trackCount(),
            releaseGroup: $release->releaseGroup,
        );
    }

    /** A UUID-shaped MusicBrainz release ID numbered `$number`. */
    private static function releaseId(int $number): string
    {
        return sprintf('0e1ea5e0-0000-4000-8000-%012d', $number);
    }

    private function apply(ApplyMatchCommand $command): MatchApplyResult
    {
        return static::getContainer()->get(ApplyMatchHandler::class)($command);
    }

    /** The release as the matcher decides on it: scored against the album as stored. */
    private function candidate(string $albumPublicId, MusicBrainzRelease $release): MatchCandidate
    {
        $snapshot = static::getContainer()->get(MatchSnapshots::class)->album($albumPublicId);
        $scored = static::getContainer()->get(CandidateScorer::class)->scoreRelease($release, $snapshot->localAlbum());

        return new MatchCandidate($release, $scored->score, $scored->pairs, [$release]);
    }

    private function compare(MatchItemType $type, string $publicId, ?MusicBrainzRelease $release, ?string $candidateId = null): MatchComparison
    {
        if ($release !== null) {
            $this->musicBrainz->releases[$release->id] = $release;
        }

        return static::getContainer()->get(CompareMatchHandler::class)(new CompareMatchQuery($type, $publicId, $candidateId ?? $release->id ?? ''));
    }

    private function storeLibrary(): string
    {
        $id = (new Uuid())->toString();
        $slug = 'match-apply-' . (random_bytes(4) |> bin2hex(...));
        $this->connection()->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', ?, ?, 'music', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $slug, '/' . $slug],
        );

        return $id;
    }

    /** @return array{string, string} the album's ID and public ID */
    private function storeAlbum(?int $year = null): array
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, year, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', ?, clock_timestamp(), clock_timestamp())",
            [$id, $publicId, $this->libraryId, self::ALBUM_TITLE, $year],
        );

        return [$id, $publicId];
    }

    /** @return array{string, string} the artist's ID and public ID */
    private function storeArtist(string $name, ?string $mbid = null): array
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $this->connection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, $publicId, $name, $mbid],
        );

        return [$id, $publicId];
    }

    /**
     * A song tagged with the title, position and disc of the release's track at the position on the
     * first disc, 1.5 seconds longer than the release says.
     *
     * @param list<array{0: string, 1: string, 2: string}> $credits artist ID, role and credited name
     *
     * @return array{string, string} the song's ID and public ID
     */
    private function storeSong(string $albumId, MusicBrainzRelease $release, int $position, array $credits): array
    {
        $track = $release->media[0]->tracks[$position - 1];
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, track, disc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, 'audio/flac', ?, ?, 1, clock_timestamp(), clock_timestamp())",
            [$id, $publicId, $albumId, $track->title, '/music/' . $id . '.flac', ($track->lengthMs ?? 0) / 1000 + 1.5, $position],
        );
        foreach ($credits as [$artistId, $role, $creditedName]) {
            $this->connection()->executeStatement(
                'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
                [(new Uuid())->toString(), $artistId, $id, $role, $creditedName],
            );
        }

        return [$id, $publicId];
    }

    private function storeMatchState(string $albumId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score) VALUES (?, 'needs_review', 'ingest', clock_timestamp(), 'close_runner_up', 2, clock_timestamp(), 88)",
            [$albumId],
        );
    }

    /** @return string the ID of a cover image stored for the artist and set as its cover */
    private function storeCover(string $artistId): string
    {
        $path = 'images/artists/' . $artistId . '.png';
        static::getContainer()->get(StoragePortInterface::class)->storeFromBytes(base64_decode(self::PIXEL), $path);
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO images (id, public_id, path, extension, mime_type, size, width, height, imageable_type, artist_id, created_at, updated_at) VALUES (?, ?, ?, 'png', 'image/png', 5, 1, 1, 'artist', ?, clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $path, $artistId],
        );
        $this->connection()->executeStatement('UPDATE artists SET cover_image_id = ? WHERE id = ?', [$id, $artistId]);

        return $id;
    }

    /** @return list<string> the files in the test's storage directory */
    private function storedFiles(): array
    {
        if (!is_dir($this->storageRoot)) {
            return [];
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS));

        return array_map(strval(...), iterator_to_array($files, false));
    }

    private function artistNamed(string $name): string
    {
        $id = $this->connection()->fetchOne('SELECT id::text FROM artists WHERE name = ?', [$name]);
        self::assertIsString($id, 'No artist named ' . $name . '.');

        return $id;
    }

    /** @return list<string> the IDs of the artists whose name matches the LIKE pattern */
    private function artistsNamedLike(string $pattern): array
    {
        return $this->connection()->fetchFirstColumn('SELECT id::text FROM artists WHERE name LIKE ? ORDER BY id', [$pattern])
            |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    /** @return list<array{string, string}> ID and name of every artist, by name */
    private function artistRows(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1]],
            $this->connection()->fetchAllNumeric('SELECT id::text, name FROM artists ORDER BY name'),
        );
    }

    /** @return list<array{string, string, string}> artist, role and credited name of each credit of the song, primary first */
    private function songCredits(string $songId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]],
            $this->connection()->fetchAllNumeric(
                "SELECT artist_id::text, role, credited_name FROM artist_song WHERE song_id = ? ORDER BY role <> 'primary', credited_name",
                [$songId],
            ),
        );
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function isolateSchema(): void
    {
        $connection = $this->connection();
        $this->searchPath = (string) $connection->fetchOne('SHOW search_path');
        $this->schema = 'match_apply_test_' . (random_bytes(8) |> bin2hex(...));
        $schema = $connection->quoteIdentifier($this->schema);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
    }
}
