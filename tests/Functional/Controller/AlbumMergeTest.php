<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Auth\Domain\Model\User;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcherInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcomeKind;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Favorites\Infrastructure\Doctrine\Entity\UserFavoriteEntity;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch\MusicBrainzReleaseFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class AlbumMergeTest extends TestCase
{
    use MusicBrainzReleaseFixtures;

    private const string RELEASE_ID = '0e1ea5e0-0000-4000-8000-000000000001';

    /** A recording ID a song took from the match of the album it is merged away from. */
    private const string EARLIER_RECORDING_ID = '5ec0bd00-0000-4000-8000-00090000000a';

    /** When the fixtures' matches compared their songs. */
    private const string COMPARED_AT = '2026-11-14 12:00:00+00';

    private AlbumRepositoryInterface $albumRepository;
    private SongRepositoryInterface $songRepository;
    private AlbumMatchStateRepositoryInterface $matchStates;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->albumRepository = $container->get(AlbumRepositoryInterface::class);
        $this->songRepository = $container->get(SongRepositoryInterface::class);
        $this->matchStates = $container->get(AlbumMatchStateRepositoryInterface::class);
    }

    public function testMergeCombinesSongsFromBothAlbums(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        // Create two albums
        $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
        $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');

        // Add songs to both albums (different hashes = no duplicates)
        $this->createSongFixture($targetAlbumId, 'song1.mp3', 'hash1', 'Song 1');
        $this->createSongFixture($targetAlbumId, 'song2.mp3', 'hash2', 'Song 2');
        $this->createSongFixture($sourceAlbumId, 'song3.mp3', 'hash3', 'Song 3');
        $this->createSongFixture($sourceAlbumId, 'song4.mp3', 'hash4', 'Song 4');

        $targetAlbum = $this->albumRepository->findByUuid($targetAlbumId);
        $sourceAlbum = $this->albumRepository->findByUuid($sourceAlbumId);

        $this->assertNotNull($targetAlbum);
        $this->assertNotNull($sourceAlbum);

        $targetSongCount = count($this->songRepository->findByAlbum($targetAlbumId));
        $sourceSongCount = count($this->songRepository->findByAlbum($sourceAlbumId));

        $this->assertSame(2, $targetSongCount);
        $this->assertSame(2, $sourceSongCount);

        // Merge albums
        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $targetAlbum->getPublicId()->toString(),
            'sourcePublicId' => $sourceAlbum->getPublicId()->toString(),
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');

        // Verify merge happened
        $this->assertArrayNotHasKey('uuid', $data['data']);
        $this->assertSame($targetAlbum->getPublicId()->toString(), $data['data']['publicId']);

        // Source album should be deleted
        $deletedSource = $this->albumRepository->findByUuid($sourceAlbumId);
        $this->assertNull($deletedSource);

        // Target should now have all 4 songs
        $finalSongCount = count($this->songRepository->findByAlbum($targetAlbumId));
        $this->assertSame(4, $finalSongCount);

        // Target should have merge record
        $updatedTarget = $this->albumRepository->findByUuid($targetAlbumId);
        $this->assertNotNull($updatedTarget);
        $mergedFrom = $updatedTarget->getMergedFrom();
        $this->assertCount(1, $mergedFrom);
        $this->assertSame($sourceAlbumId->toString(), $mergedFrom[0]['id']);
        $this->assertSame('Source Album', $mergedFrom[0]['title']);
    }

    public function testMergeDeduplicatesSongsByHash(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        // Create two albums
        $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
        $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');

        // Add songs with same hash (duplicates)
        $this->createSongFixture($targetAlbumId, 'song1.mp3', 'same-hash', 'Song 1');
        $this->createSongFixture($sourceAlbumId, 'song1-dup.mp3', 'same-hash', 'Song 1 Duplicate');

        $targetAlbum = $this->albumRepository->findByUuid($targetAlbumId);
        $sourceAlbum = $this->albumRepository->findByUuid($sourceAlbumId);

        $this->assertNotNull($targetAlbum);
        $this->assertNotNull($sourceAlbum);

        // Merge albums
        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $targetAlbum->getPublicId()->toString(),
            'sourcePublicId' => $sourceAlbum->getPublicId()->toString(),
        ]);

        $this->assertJsonResponse($response, 200, 'data');

        // Target should still have only 1 song (deduplicated)
        $finalSongCount = count($this->songRepository->findByAlbum($targetAlbumId));
        $this->assertSame(1, $finalSongCount);
    }

    public function testMergeReturns400ForSameAlbum(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        $albumId = $this->createAlbumFixture($libraryId, 'Album');
        $album = $this->albumRepository->findByUuid($albumId);

        $this->assertNotNull($album);

        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $album->getPublicId()->toString(),
            'sourcePublicId' => $album->getPublicId()->toString(),
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMergeReturns400ForDifferentLibraries(): void
    {
        $user = $this->createAdminUser();

        $library1Id = $this->createLibraryFixture();
        $library2Id = $this->createLibraryFixture();

        $album1Id = $this->createAlbumFixture($library1Id, 'Album 1');
        $album2Id = $this->createAlbumFixture($library2Id, 'Album 2');

        $album1 = $this->albumRepository->findByUuid($album1Id);
        $album2 = $this->albumRepository->findByUuid($album2Id);

        $this->assertNotNull($album1);
        $this->assertNotNull($album2);

        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $album1->getPublicId()->toString(),
            'sourcePublicId' => $album2->getPublicId()->toString(),
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMergeReturns404ForMissingTarget(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');
        $sourceAlbum = $this->albumRepository->findByUuid($sourceAlbumId);

        $this->assertNotNull($sourceAlbum);

        $fakePublicId = new PublicId();

        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $fakePublicId->toString(),
            'sourcePublicId' => $sourceAlbum->getPublicId()->toString(),
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMergeReturns404ForMissingSource(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
        $targetAlbum = $this->albumRepository->findByUuid($targetAlbumId);

        $this->assertNotNull($targetAlbum);

        $fakePublicId = new PublicId();

        $response = $this->authenticatedRequest('POST', '/api/albums/merge', $user, [
            'targetPublicId' => $targetAlbum->getPublicId()->toString(),
            'sourcePublicId' => $fakePublicId->toString(),
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The API and the console answer a merge alike and leave the same albums, songs, favorites,
     * plays, playlist entries and lyrics: a user who favorited the source alone, and one who
     * favorited both albums, each hold one favorite of the target, the plays of a moved song count
     * for the target, and the playlist entry, lyrics, play and favorite of the source's copy of a
     * target song are the target song's.
     */
    public function testConsoleMergeMatchesTheApiMerge(): void
    {
        $user = $this->createAdminUser();
        $outcomes = [];

        foreach (['api', 'console'] as $path) {
            $libraryId = $this->createLibraryFixture();
            $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
            $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');
            $kept = $this->createSongFixture($targetAlbumId, $path . '-1.mp3', $path . '-shared', 'Song 1');
            $duplicate = $this->createSongFixture($sourceAlbumId, $path . '-1-dup.mp3', $path . '-shared', 'Song 1 Duplicate');
            $moved = $this->createSongFixture($sourceAlbumId, $path . '-2.mp3', $path . '-2', 'Song 2');
            $sourceFan = $this->createTestUser()->getId();
            $this->createFavoriteFixture($sourceFan, $sourceAlbumId);
            $play = $this->createPlayFixture($sourceFan, $moved, $sourceAlbumId);
            $playlist = $this->createPlaylistFixture($sourceFan, [$duplicate->toString() => 0]);
            $this->createLyricsFixture($duplicate, 'Here come old flat-top', 4711);
            $this->createPlayFixture($sourceFan, $duplicate, $sourceAlbumId);
            $this->createSongFavoriteFixture($sourceFan, $duplicate);
            $songNames = [$kept->toString() => 'kept', $duplicate->toString() => 'duplicate', $moved->toString() => 'moved'];
            $songPublicNames = [$this->songPublicIdOf($kept) => 'kept', $this->songPublicIdOf($duplicate) => 'duplicate'];
            $fanOfBoth = $this->createTestUser()->getId();
            $this->createFavoriteFixture($fanOfBoth, $sourceAlbumId);
            $this->createFavoriteFixture($fanOfBoth, $targetAlbumId);
            $albumNames = [$this->publicIdOf($targetAlbumId) => 'target', $this->publicIdOf($sourceAlbumId) => 'source'];
            $target = $this->albumRepository->findByUuid($targetAlbumId);
            $source = $this->albumRepository->findByUuid($sourceAlbumId);
            $this->assertNotNull($target);
            $this->assertNotNull($source);

            if ($path === 'api') {
                $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/albums/merge', $user, [
                    'targetPublicId' => $target->getPublicId()->toString(),
                    'sourcePublicId' => $source->getPublicId()->toString(),
                ]), 200, 'data');
            } else {
                $merge = new CommandTester((new Application($this->client->getKernel()))->find('app:album:merge'));
                $this->assertSame(Command::SUCCESS, $merge->execute([
                    'target' => $target->getPublicId()->toString(),
                    'source' => $source->getPublicId()->toString(),
                    '--force' => true,
                ], ['interactive' => false]), $merge->getDisplay());
            }

            $this->entityManager->clear();
            $merged = $this->albumRepository->findByUuid($targetAlbumId);
            $this->assertNotNull($merged);
            $outcomes[$path] = [
                'sourceDeleted' => $this->albumRepository->findByUuid($sourceAlbumId) === null,
                'songs' => count($this->songRepository->findByAlbum($targetAlbumId)),
                'mergedFrom' => array_map(
                    static fn (array $record): array => [$record['id'] === $sourceAlbumId->toString(), $record['title']],
                    $merged->getMergedFrom(),
                ),
                'favorites' => [
                    'sourceFan' => array_map(static fn (string $album): string => $albumNames[$album] ?? $album, $this->albumFavoritesOf($sourceFan)),
                    'fanOfBoth' => array_map(static fn (string $album): string => $albumNames[$album] ?? $album, $this->albumFavoritesOf($fanOfBoth)),
                ],
                'play' => $this->playAlbumOf($play) === $targetAlbumId->toString() ? 'target' : $this->playAlbumOf($play),
                'copyOfASong' => [
                    'entries' => $this->entriesOf($playlist) |> (static function (array $entries) use ($songNames): array {
                        $songIds = array_keys($entries);

                        return array_combine(array_map(static fn (string $song): string => $songNames[$song] ?? $song, $songIds), $entries);
                    }),
                    'lyrics' => array_map(
                        static fn (string $song): string => $songNames[$song] ?? $song,
                        $this->entityManager->getConnection()->fetchFirstColumn("SELECT song_id FROM lyrics WHERE plain_lyrics = 'Here come old flat-top' AND song_id IN (?, ?)", [$kept->toString(), $duplicate->toString()]),
                    ),
                    'plays' => array_map(static fn (array $row): string => $songNames[$row[1]] ?? $row[1], $this->playsOf($sourceFan)) |> (static function (array $songs): array {
                        sort($songs);

                        return $songs;
                    }),
                    'favorites' => array_map(static fn (string $song): string => $songPublicNames[$song] ?? $song, $this->songFavoritesOf($sourceFan)),
                ],
            ];
        }

        $this->assertSame([
            'sourceDeleted' => true,
            'songs' => 2,
            'mergedFrom' => [[true, 'Source Album']],
            'favorites' => ['sourceFan' => ['target'], 'fanOfBoth' => ['target']],
            'play' => 'target',
            'copyOfASong' => [
                'entries' => ['kept' => 0],
                'lyrics' => ['kept'],
                'plays' => ['kept', 'moved'],
                'favorites' => ['kept'],
            ],
        ], $outcomes['api']);
        $this->assertSame($outcomes['api'], $outcomes['console']);
    }

    public function testConsoleMergeRefusesAlbumsInDifferentLibrariesLikeTheApi(): void
    {
        $album1Id = $this->createAlbumFixture($this->createLibraryFixture(), 'Album 1');
        $album2Id = $this->createAlbumFixture($this->createLibraryFixture(), 'Album 2');
        $album1 = $this->albumRepository->findByUuid($album1Id);
        $album2 = $this->albumRepository->findByUuid($album2Id);
        $this->assertNotNull($album1);
        $this->assertNotNull($album2);

        $merge = new CommandTester((new Application($this->client->getKernel()))->find('app:album:merge'));

        $this->assertSame(Command::INVALID, $merge->execute([
            'target' => $album1->getPublicId()->toString(),
            'source' => $album2->getPublicId()->toString(),
            '--force' => true,
        ], ['interactive' => false]));
        $this->assertStringContainsString('Albums must be in the same library to merge.', $merge->getDisplay());
        $this->assertNotNull($this->albumRepository->findByUuid($album2Id));
    }

    public function testAMergeUnmarksTheMovedSongsAndRequestsAMatchOfTheTargetFromTheApiAndTheConsole(): void
    {
        $this->setAutomaticMatching(true);
        $admin = $this->createAdminUser();

        foreach (['api', 'console'] as $path) {
            $albums = $this->createComparedAlbumsFixture($path);
            $keptMark = $this->songRow($albums['kept'])['match_checked_at'];
            $this->assertNotNull($keptMark);
            $earliest = new \DateTimeImmutable('+5 minutes');

            if ($path === 'api') {
                $this->assertJsonResponse($this->mergeThroughTheApi($admin, $albums['target'], $albums['source']), 200, 'data');
            } else {
                $merge = new CommandTester((new Application($this->client->getKernel()))->find('app:album:merge'));
                $this->assertSame(Command::SUCCESS, $merge->execute([
                    'target' => $this->publicIdOf($albums['target']),
                    'source' => $this->publicIdOf($albums['source']),
                    '--force' => true,
                ], ['interactive' => false]), $merge->getDisplay());
            }

            $moved = $this->songRow($albums['moved']);
            $this->assertSame($albums['target']->toString(), $moved['album_id'], $path);
            $this->assertNull($moved['match_checked_at'], $path . ': no match has compared the moved song with the target\'s release.');
            $this->assertSame(self::EARLIER_RECORDING_ID, $moved['mbid'], $path);
            $this->assertSame($keptMark, $this->songRow($albums['kept'])['match_checked_at'], $path . ': the target\'s own song keeps its mark.');
            $state = $this->matchStates->findByAlbum($albums['target']);
            $this->assertSame(AlbumMatchStatus::Requested, $state?->status, $path);
            $this->assertSame(AlbumMatchSource::Ingest, $state->source, $path);
            $this->assertGreaterThanOrEqual($earliest, $state->dueAt, $path);
            $this->assertLessThanOrEqual(new \DateTimeImmutable('+5 minutes'), $state->dueAt, $path);
        }
    }

    public function testAMergeRecordsNoMatchRequestWhileAutomaticMatchingIsOff(): void
    {
        $admin = $this->createAdminUser();
        $albums = $this->createComparedAlbumsFixture('off');

        $this->assertJsonResponse($this->mergeThroughTheApi($admin, $albums['target'], $albums['source']), 200, 'data');

        $this->assertNull($this->songRow($albums['moved'])['match_checked_at'], 'A match of the target would have a song to compare.');
        $this->assertNull($this->matchStates->findByAlbum($albums['target']));
    }

    public function testAMergeWhoseMatchRequestFailsStillAnswers200AndHasMovedTheSongs(): void
    {
        $requests = $this->createMock(AlbumMatchRequestInterface::class);
        $requests->expects($this->once())->method('requestAfterImport')->willThrowException(new \RuntimeException('The album match queue cannot be reached.'));
        static::getContainer()->set(AlbumMatchRequestInterface::class, $requests);
        $admin = $this->createAdminUser();
        $albums = $this->createComparedAlbumsFixture('failed-request');

        $this->assertJsonResponse($this->mergeThroughTheApi($admin, $albums['target'], $albums['source']), 200, 'data');

        $this->assertSame($albums['target']->toString(), $this->songRow($albums['moved'])['album_id']);
        $this->assertSame(2, $this->songCount($albums['target']));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM albums WHERE id = ?', [$albums['source']->toString()]));
    }

    public function testAMergeIntoAnAlbumLeftForReviewRequestsItAgainOnlyWhenItsSongCountChanged(): void
    {
        $this->setAutomaticMatching(true);
        $admin = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        $grown = $this->createAlbumFixture($libraryId, 'Target Album');
        $this->createSongFixture($grown, 'grown-1.mp3', 'grown-1', 'Song 1');
        $this->createReviewFixture($grown, checkedSongCount: 1);
        $secondDisc = $this->createAlbumFixture($libraryId, 'Target Album (Disc 2)');
        $this->createSongFixture($secondDisc, 'grown-2.mp3', 'grown-2', 'Song 2');
        $unchanged = $this->createAlbumFixture($libraryId, 'Other Album');
        $this->createSongFixture($unchanged, 'unchanged-1.mp3', 'unchanged-1', 'Song 1');
        $this->createReviewFixture($unchanged, checkedSongCount: 1);
        $copy = $this->createAlbumFixture($libraryId, 'Other Album (Copy)');
        $this->createSongFixture($copy, 'unchanged-1-copy.mp3', 'unchanged-1', 'Song 1');

        $this->assertJsonResponse($this->mergeThroughTheApi($admin, $grown, $secondDisc), 200, 'data');
        $this->assertJsonResponse($this->mergeThroughTheApi($admin, $unchanged, $copy), 200, 'data');

        $this->assertSame(2, $this->songCount($grown));
        $this->assertSame(AlbumMatchStatus::Requested, $this->matchStates->findByAlbum($grown)?->status, 'The matcher checked one song and the album now holds two.');
        $this->assertSame(1, $this->songCount($unchanged));
        $this->assertSame(AlbumMatchStatus::NeedsReview, $this->matchStates->findByAlbum($unchanged)?->status, 'The merge removed a duplicate only, so the album holds the song count the matcher checked.');
    }

    public function testTwoAlbumsMergeIntoOneTargetOneAfterTheOtherInOneProcess(): void
    {
        $this->setAutomaticMatching(true);
        $albums = $this->createComparedAlbumsFixture('first');
        $second = $this->createAlbumFixture($albums['library'], 'Second Source Album');
        $movedSecond = $this->createSongFixture($second, 'second-moved.mp3', 'second-moved', 'Song 3', track: 3, compared: true);
        $this->createSongFixture($second, 'second-kept-copy.mp3', 'first-kept', 'Song 1 Duplicate', compared: true);
        $merge = static::getContainer()->get(AlbumMergePortInterface::class);

        $merge->mergeAlbums($albums['target'], $albums['source']);
        $merge->mergeAlbums($albums['target'], $second);

        $this->assertSame(3, $this->songCount($albums['target']));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM albums WHERE id IN (?, ?)',
            [$albums['source']->toString(), $second->toString()],
        ));
        $this->assertNull($this->songRow($albums['moved'])['match_checked_at']);
        $this->assertNull($this->songRow($movedSecond)['match_checked_at']);
        $this->assertSame(AlbumMatchStatus::Requested, $this->matchStates->findByAlbum($albums['target'])?->status);
    }

    /**
     * The match a merge requested compares the moved songs with the target's release: one whose
     * length fits the track at its number takes that track's recording ID and credits, one 40
     * seconds off is left as it was, and one that already holds a recording ID keeps it.
     */
    public function testTheMatchAMergeRequestedGivesAMovedSongATrackOnlyWhenItsLengthFits(): void
    {
        $this->setAutomaticMatching(true);
        $release = self::release(self::RELEASE_ID, [4]);
        $musicBrainz = new FakeMusicBrainzLookup();
        $musicBrainz->releases[$release->id] = $release;
        static::getContainer()->set(MusicBrainzLookupInterface::class, $musicBrainz);
        $admin = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        $postMalone = $this->createArtistFixture(self::ALBUM_ARTIST, self::artistId(self::ALBUM_ARTIST));
        $timMcGraw = $this->createArtistFixture('Tim McGraw');
        $target = $this->createAlbumFixture($libraryId, self::ALBUM_TITLE);
        $this->createMatchFixture($target, $release->id);
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
            [Uuid::v7()->toString(), $postMalone->toString(), $target->toString(), self::ALBUM_ARTIST],
        );
        $own = $this->createSongFixture($target, 'long-bed-1.flac', 'long-bed-1', 'Track 1-1', track: 1, length: 151.5, recordingId: self::recordingId(1, 1), compared: true);
        $this->createCreditFixture($own, $postMalone, self::ALBUM_ARTIST);
        $source = $this->createAlbumFixture($libraryId, 'Country Duets');
        $fits = $this->createSongFixture($source, 'duets-2.flac', 'duets-2', 'Track 1-2', track: 2, length: 158.5, compared: true);
        $this->createCreditFixture($fits, $timMcGraw, 'Tim McGraw');
        $differs = $this->createSongFixture($source, 'duets-3.flac', 'duets-3', 'Track 1-3', track: 3, length: 204.0, compared: true);
        $this->createCreditFixture($differs, $timMcGraw, 'Tim McGraw');
        $holdsRecording = $this->createSongFixture($source, 'duets-4.flac', 'duets-4', 'Track 1-4', track: 4, length: 172.5, recordingId: self::EARLIER_RECORDING_ID, compared: true);
        $this->createCreditFixture($holdsRecording, $postMalone, self::ALBUM_ARTIST);

        $this->assertJsonResponse($this->mergeThroughTheApi($admin, $target, $source), 200, 'data');
        $this->assertSame(AlbumMatchStatus::Requested, $this->matchStates->findByAlbum($target)?->status);
        $outcome = static::getContainer()->get(AutomaticAlbumMatcherInterface::class)->match($target);

        $this->assertSame(AutomaticMatchOutcomeKind::Matched, $outcome->kind);
        $this->assertSame(self::recordingId(1, 2), $this->songRow($fits)['mbid']);
        $this->assertSame([self::ALBUM_ARTIST], $this->creditsOf($fits));
        $this->assertNull($this->songRow($differs)['mbid']);
        $this->assertSame(['Tim McGraw'], $this->creditsOf($differs));
        $this->assertNotNull($this->songRow($differs)['match_checked_at'], 'The match compared the song and found no track for it.');
        $this->assertSame(self::EARLIER_RECORDING_ID, $this->songRow($holdsRecording)['mbid']);
        $this->assertNotNull($this->songRow($holdsRecording)['match_checked_at']);
    }

    /**
     * The manager that merges has loaded the source's credit, genre link and cover before: the
     * database deletes the credit and the link with the source and clears the cover's reference to
     * it, so the merge releases what the manager holds of them and saves the target. The cover is
     * discarded once the merge committed.
     */
    public function testAMergeSucceedsAfterTheSameManagerLoadedTheSourcesCreditsGenreLinksAndCover(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $moved = $this->createSongFixture($source, 'loaded-moved.mp3', 'loaded-moved', 'Song 2', track: 2);
        $artist = $this->createArtistFixture('Loaded Artist');
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, 'primary', 'Loaded Artist')",
            [Uuid::v7()->toString(), $artist->toString(), $source->toString()],
        );
        $genre = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO genres (id, public_id, name, slug, created_at, updated_at) VALUES (?, ?, 'Loaded Genre', ?, clock_timestamp(), clock_timestamp())",
            [$genre->toString(), (new PublicId())->toString(), 'loaded-genre-' . bin2hex(random_bytes(4))],
        );
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO genre_album (id, genre_id, album_id) VALUES (?, ?, ?)',
            [Uuid::v7()->toString(), $genre->toString(), $source->toString()],
        );
        $cover = $this->createCoverFixture($source);

        self::assertCount(1, $this->entityManager->getRepository(ArtistAlbumEntity::class)->findBy(['album' => $source]));
        self::assertCount(1, $this->entityManager->getRepository(GenreAlbumEntity::class)->findBy(['album' => $source]));
        self::assertInstanceOf(ImageEntity::class, $this->entityManager->find(ImageEntity::class, $cover));

        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM albums WHERE id = ?', [$source->toString()]));
        $this->assertSame($target->toString(), $this->songRow($moved)['album_id']);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM artist_album WHERE album_id = ?', [$source->toString()]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM genre_album WHERE album_id = ?', [$source->toString()]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM images WHERE id = ?', [$cover->toString()]), 'The source\'s cover is discarded.');
    }

    /**
     * The manager that runs the merge has loaded two favorites of the source, one of a user who
     * favorited the target as well, and a play of the source's song. The merge moves favorites and
     * plays with statements and lets go of what the manager held of them: the manager reads the
     * moved favorite and the play as naming the target, finds no favorite the merge dropped, and a
     * flush afterwards leaves the rows as the merge wrote them.
     */
    public function testAMergeReleasesTheFavoritesAndPlaysOfTheSourceTheSameManagerLoaded(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $moved = $this->createSongFixture($source, 'loaded-play.mp3', 'loaded-play', 'Song 2', track: 2);
        $sourceFan = $this->createTestUser()->getId();
        $movedFavorite = $this->createFavoriteFixture($sourceFan, $source);
        $play = $this->createPlayFixture($sourceFan, $moved, $source);
        $fanOfBoth = $this->createTestUser()->getId();
        $droppedFavorite = $this->createFavoriteFixture($fanOfBoth, $source);
        $keptFavorite = $this->createFavoriteFixture($fanOfBoth, $target);
        $sourcePublicId = $this->publicIdOf($source);
        $targetPublicId = $this->publicIdOf($target);
        foreach ([$movedFavorite, $droppedFavorite] as $favorite) {
            self::assertSame($sourcePublicId, $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());
        }
        self::assertSame($source->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getAlbum()?->getId()->toString());

        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

        $this->assertSame($targetPublicId, $this->entityManager->find(UserFavoriteEntity::class, $movedFavorite)?->getEntityPublicId());
        $this->assertNull($this->entityManager->find(UserFavoriteEntity::class, $droppedFavorite), 'The merge dropped the favorite of a user who favorited both albums.');
        $this->assertSame($target->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getAlbum()?->getId()->toString());
        $this->entityManager->flush();
        $this->assertSame([$targetPublicId], $this->albumFavoritesOf($sourceFan));
        $this->assertSame(
            [$keptFavorite->toString()],
            $this->entityManager->getConnection()->fetchFirstColumn("SELECT id FROM user_favorites WHERE user_id = ? AND entity_type = 'album'", [$fanOfBoth->toString()]),
            'The user who favorited both albums keeps the favorite of the target.',
        );
        $this->assertSame($target->toString(), $this->playAlbumOf($play));
    }

    /**
     * The database ends a merge to break a deadlock with another catalog writer. The API answers 409
     * with the reason `merge_busy`, the console exits with 1 and prints the same message, and both
     * albums stay as they were.
     */
    public function testAMergeEndedToBreakADeadlockIsAConflictOnBothPaths(): void
    {
        static::getContainer()->set(TransactionPortInterface::class, new class implements TransactionPortInterface {
            public function run(callable $operation): mixed
            {
                throw new TransactionDeadlockException(new \RuntimeException('deadlock detected'));
            }
        });
        $admin = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $moved = $this->createSongFixture($source, 'busy-moved.mp3', 'busy-moved', 'Song 2', track: 2);
        $message = 'Another change to "Source Album" or "Target Album" ran at the same time and nothing was merged. Merge the albums again.';

        $error = $this->assertJsonResponse($this->mergeThroughTheApi($admin, $target, $source), 409);
        $this->assertSame('merge_busy', $error['error']['details']['reason'] ?? null);
        $this->assertSame($message, $error['error']['message'] ?? null);

        $merge = new CommandTester((new Application($this->client->getKernel()))->find('app:album:merge'));
        $this->assertSame(Command::FAILURE, $merge->execute([
            'target' => $this->publicIdOf($target),
            'source' => $this->publicIdOf($source),
            '--force' => true,
        ], ['interactive' => false]));
        $this->assertStringContainsString($message, preg_replace('/\s+/', ' ', $merge->getDisplay(true)) ?? '');

        $this->assertSame($source->toString(), $this->songRow($moved)['album_id']);
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM albums WHERE id = ?', [$source->toString()]));
    }

    /**
     * A playlist holds the source's copy of "Song 1" at position 0 and the target's song at
     * position 3. A user played the copy three times, last on the web, and loved it, and the
     * target's song twice, earlier, on the app. After the merge the playlist holds the target's
     * song once, at position 3, and the user has one play row of it, the target song's own: five
     * plays, the copy's last-played time with its platform and player, and the loved flag.
     */
    public function testAMergeFoldsTheCopysPlaylistEntryAndPlaysIntoTheTargetsSong(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $kept = $this->createSongFixture($target, 'fold-kept.mp3', 'fold-shared', 'Song 1');
        $duplicate = $this->createSongFixture($source, 'fold-copy.mp3', 'fold-shared', 'Song 1 Duplicate');
        $listener = $this->createTestUser()->getId();
        $playlist = $this->createPlaylistFixture($listener, [$duplicate->toString() => 0, $kept->toString() => 3]);
        $this->createPlayFixture($listener, $duplicate, $source, count: 3, love: true, lastPlayedAt: '2026-11-10 20:00:00.5+00', platform: 'web', player: 'Firefox');
        $keptPlay = $this->createPlayFixture($listener, $kept, $target, count: 2, love: false, lastPlayedAt: '2026-11-01 08:00:00.25+00', platform: 'ios', player: 'Baander');

        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

        $this->assertSame([$kept->toString() => 3], $this->entriesOf($playlist));
        $this->assertSame(
            [[$keptPlay->toString(), $kept->toString(), $target->toString(), 5, true, '2026-11-10 20:00:00.5+00', 'web', 'Firefox']],
            $this->playsOf($listener),
        );
    }

    /**
     * The source holds two copies of the target's "Song 1". A user holds both copies in one
     * playlist, favorited both and played both. After the merge the playlist holds the target's
     * song once, at the position of the copy folded first, the one with the lower ID; the user holds
     * one favorite of it, and one play row of it with the plays of both copies.
     */
    public function testTwoCopiesOfOneSongBothFoldIntoIt(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $kept = $this->createSongFixture($target, 'twice-kept.mp3', 'twice-shared', 'Song 1');
        $copies = [
            $this->createSongFixture($source, 'twice-copy-1.mp3', 'twice-shared', 'Song 1 (Copy 1)'),
            $this->createSongFixture($source, 'twice-copy-2.mp3', 'twice-shared', 'Song 1 (Copy 2)'),
        ];
        $listener = $this->createTestUser()->getId();
        $playlist = $this->createPlaylistFixture($listener, [$copies[0]->toString() => 4, $copies[1]->toString() => 7]);
        $plays = [];
        foreach ($copies as $copy) {
            $this->createSongFavoriteFixture($listener, $copy);
            $plays[] = $this->createPlayFixture($listener, $copy, $source, count: 3);
        }
        $first = strcmp($copies[0]->toString(), $copies[1]->toString()) < 0 ? 0 : 1;

        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

        $this->assertSame([$kept->toString() => [4, 7][$first]], $this->entriesOf($playlist));
        $this->assertSame([$this->songPublicIdOf($kept)], $this->songFavoritesOf($listener));
        $this->assertSame([[$plays[$first]->toString(), $kept->toString(), $target->toString(), 6]], array_map(
            static fn (array $play): array => array_slice($play, 0, 4),
            $this->playsOf($listener),
        ));
    }

    /**
     * The manager that runs the merge has loaded a playlist entry of the source's copy of a target
     * song, the copy's lyrics, a user's play of the copy and that user's favorite of it. The merge
     * folds them with statements and lets go of what the manager held of them: it completes, the
     * manager reads each of them as the target song's, and a flush afterwards leaves the rows as the
     * merge wrote them.
     */
    public function testAMergeSucceedsAfterTheSameManagerLoadedWhatUsersHoldOnTheCopy(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $kept = $this->createSongFixture($target, 'held-kept.mp3', 'held-shared', 'Song 1');
        $duplicate = $this->createSongFixture($source, 'held-copy.mp3', 'held-shared', 'Song 1 Duplicate');
        $listener = $this->createTestUser()->getId();
        $playlist = $this->createPlaylistFixture($listener, [$duplicate->toString() => 2]);
        $lyrics = $this->createLyricsFixture($duplicate, 'Here come old flat-top', 4711);
        $play = $this->createPlayFixture($listener, $duplicate, $source, count: 3);
        $favorite = $this->createSongFavoriteFixture($listener, $duplicate);
        $entry = $this->entityManager->getRepository(PlaylistSongEntity::class)->findOneBy(['songId' => $duplicate]);
        self::assertInstanceOf(PlaylistSongEntity::class, $entry);
        self::assertSame($duplicate->toString(), $this->entityManager->find(LyricsEntity::class, $lyrics)?->getSongId()->toString());
        self::assertSame($duplicate->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getSong()?->getId()->toString());
        self::assertSame($this->songPublicIdOf($duplicate), $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());

        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

        $this->assertSame($kept->toString(), $this->entityManager->find(PlaylistSongEntity::class, $entry->getId())?->getSongId()->toString());
        $this->assertSame($kept->toString(), $this->entityManager->find(LyricsEntity::class, $lyrics)?->getSongId()->toString());
        $this->assertSame($kept->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getSong()?->getId()->toString());
        $this->assertSame($this->songPublicIdOf($kept), $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());
        $this->entityManager->flush();
        $this->assertSame([$kept->toString() => 2], $this->entriesOf($playlist));
        $this->assertSame([$kept->toString()], $this->entityManager->getConnection()->fetchFirstColumn('SELECT song_id FROM lyrics WHERE id = ?', [$lyrics->toString()]));
        $this->assertSame([[$play->toString(), $kept->toString(), $target->toString(), 3]], array_map(
            static fn (array $row): array => array_slice($row, 0, 4),
            $this->playsOf($listener),
        ));
        $this->assertSame([$this->songPublicIdOf($kept)], $this->songFavoritesOf($listener));
    }

    /**
     * After the merge the source's copy of a target song is gone with its own row, its credits and
     * its genre links. Its audio file is still on disk as it was, and so is the library's file
     * index row for it: the merge never touches files.
     */
    public function testAMergeRemovesTheCopysRowCreditsAndGenresAndLeavesItsFileAndFileIndexRow(): void
    {
        $libraryId = $this->createLibraryFixture();
        $target = $this->createAlbumFixture($libraryId, 'Target Album');
        $source = $this->createAlbumFixture($libraryId, 'Source Album');
        $this->createSongFixture($target, 'file-kept.mp3', 'file-shared', 'Song 1');
        $file = sys_get_temp_dir() . '/baander-album-merge-' . bin2hex(random_bytes(4)) . '.mp3';
        file_put_contents($file, 'ID3 the copy');
        $duplicate = $this->createSongFixture($source, $file, 'file-shared', 'Song 1 Duplicate');
        $this->createCreditFixture($duplicate, $this->createArtistFixture('The Beatles'), 'The Beatles');
        $genre = Uuid::v7();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO genres (id, public_id, name, slug, created_at, updated_at) VALUES (?, ?, 'Rock', ?, clock_timestamp(), clock_timestamp())",
            [$genre->toString(), (new PublicId())->toString(), 'rock-' . bin2hex(random_bytes(4))],
        );
        $connection->executeStatement('INSERT INTO genre_song (id, genre_id, song_id) VALUES (?, ?, ?)', [Uuid::v7()->toString(), $genre->toString(), $duplicate->toString()]);
        $connection->executeStatement(
            "INSERT INTO library_file_index (id, library_id, path, hash, size, extension, modified_at, discovered_at) VALUES (?, ?, ?, 'file-shared', 12, 'mp3', 1763200000, '2026-11-15 09:00:00+00')",
            [Uuid::v7()->toString(), $libraryId->toString(), $file],
        );
        $indexed = $connection->fetchAssociative('SELECT * FROM library_file_index WHERE path = ?', [$file]);

        try {
            static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target, $source);

            $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM songs WHERE id = ?', [$duplicate->toString()]));
            $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM artist_song WHERE song_id = ?', [$duplicate->toString()]));
            $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM genre_song WHERE song_id = ?', [$duplicate->toString()]));
            $this->assertSame('ID3 the copy', file_get_contents($file));
            $this->assertSame($indexed, $connection->fetchAssociative('SELECT * FROM library_file_index WHERE path = ?', [$file]));
        } finally {
            unlink($file);
        }
    }

    // --- Fixture helpers ---

    private function createLibraryFixture(): Uuid
    {
        $libraryId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
                (new PublicId())->toString(),
                'test-lib-' . bin2hex(random_bytes(4)),
                'Test Library',
                '/tmp/test-' . bin2hex(random_bytes(4)),
                'music',
                0,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $libraryId;
    }

    private function createAlbumFixture(Uuid $libraryId, string $title): Uuid
    {
        $albumId = Uuid::v7();
        $publicId = new PublicId();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, year, locked_fields, merged_from, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                $publicId->toString(),
                $libraryId->toString(),
                $title,
                'studio',
                2023,
                '{}',
                '[]',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $albumId;
    }

    /**
     * @param string|null $recordingId the MusicBrainz recording ID the song holds
     * @param bool        $compared    whether a match of the song's album has compared it with that album's release
     */
    private function createSongFixture(
        Uuid $albumId,
        string $path,
        string $hash,
        string $title,
        int $track = 1,
        float $length = 180.0,
        ?string $recordingId = null,
        bool $compared = false,
    ): Uuid {
        $songId = Uuid::v7();
        $publicId = new PublicId();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, path, hash, title, size, mime_type, length, track, disc, mbid, match_checked_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $songId->toString(),
                $publicId->toString(),
                $albumId->toString(),
                $path,
                $hash,
                $title,
                1000,
                'audio/mpeg',
                $length,
                $track,
                1,
                $recordingId,
                $compared ? self::COMPARED_AT : null,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $songId;
    }

    /**
     * A matched target album and a source album in one library, each with one song its own album's
     * match has compared; the source's song holds a recording ID from that match.
     *
     * @return array{library: Uuid, target: Uuid, source: Uuid, kept: Uuid, moved: Uuid}
     */
    private function createComparedAlbumsFixture(string $name): array
    {
        $libraryId = $this->createLibraryFixture();
        $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
        $this->createMatchFixture($targetAlbumId, self::RELEASE_ID);
        $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');

        return [
            'library' => $libraryId,
            'target' => $targetAlbumId,
            'source' => $sourceAlbumId,
            'kept' => $this->createSongFixture($targetAlbumId, $name . '-kept.mp3', $name . '-kept', 'Song 1', compared: true),
            'moved' => $this->createSongFixture($sourceAlbumId, $name . '-moved.mp3', $name . '-moved', 'Song 2', track: 2, recordingId: self::EARLIER_RECORDING_ID, compared: true),
        ];
    }

    /** Records the album as matched to the release, as an applied match does. */
    private function createMatchFixture(Uuid $albumId, string $releaseId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE albums SET release_mbid = ?, matched_at = ? WHERE id = ?',
            [$releaseId, self::COMPARED_AT, $albumId->toString()],
        );
    }

    /** Leaves the album for review as the matcher does when no candidate fits, having checked that many songs. */
    private function createReviewFixture(Uuid $albumId, int $checkedSongCount): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at) VALUES (?, 'needs_review', 'ingest', ?, 'no_candidates', ?, ?)",
            [$albumId->toString(), self::COMPARED_AT, $checkedSongCount, self::COMPARED_AT],
        );
    }

    /** Gives the album a cover image record, as an import or an apply stores one. */
    private function createCoverFixture(Uuid $albumId): Uuid
    {
        $imageId = Uuid::v7();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO images (id, public_id, path, extension, mime_type, size, width, height, imageable_type, album_id, created_at, updated_at) VALUES (?, ?, ?, 'jpg', 'image/jpeg', 5, 1, 1, 'album', ?, clock_timestamp(), clock_timestamp())",
            [$imageId->toString(), (new PublicId())->toString(), 'images/album/' . $albumId->toString() . '/cover.jpg', $albumId->toString()],
        );
        $connection->executeStatement('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$imageId->toString(), $albumId->toString()]);

        return $imageId;
    }

    private function createArtistFixture(string $name, ?string $mbid = null): Uuid
    {
        $artistId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$artistId->toString(), (new PublicId())->toString(), $name, $mbid],
        );

        return $artistId;
    }

    private function createCreditFixture(Uuid $songId, Uuid $artistId, string $creditedName): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
            [Uuid::v7()->toString(), $artistId->toString(), $songId->toString(), $creditedName],
        );
    }

    /** Stores the user's favorite of the album, by the album's public ID as Favorites stores it. */
    private function createFavoriteFixture(Uuid $userId, Uuid $albumId): Uuid
    {
        $favoriteId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at) SELECT ?, ?, ?, 'album', public_id, clock_timestamp(), clock_timestamp() FROM albums WHERE id = ?",
            [$favoriteId->toString(), (new PublicId())->toString(), $userId->toString(), $albumId->toString()],
        );

        return $favoriteId;
    }

    /**
     * Stores the user's plays of the song on the album, as the player records them: $count plays,
     * the last at $lastPlayedAt, or now, from $platform and $player.
     */
    private function createPlayFixture(
        Uuid $userId,
        Uuid $songId,
        Uuid $albumId,
        int $count = 3,
        bool $love = false,
        ?string $lastPlayedAt = null,
        ?string $platform = null,
        ?string $player = null,
    ): Uuid {
        $playId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, album_id, play_count, love, last_played_at, last_platform, last_player, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, ?, ?, ?, coalesce(?::timestamptz, clock_timestamp()), ?, ?, clock_timestamp(), clock_timestamp())",
            [$playId->toString(), (new PublicId())->toString(), $userId->toString(), $songId->toString(), $albumId->toString(), $count, $love ? 'true' : 'false', $lastPlayedAt, $platform, $player],
        );

        return $playId;
    }

    /**
     * Stores the user's playlist with these entries, song ID => position, as a save of the playlist
     * commits it.
     *
     * @param array<string, int> $entries
     */
    private function createPlaylistFixture(Uuid $ownerId, array $entries): Uuid
    {
        $playlistId = Uuid::v7();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, created_at, updated_at) VALUES (?, ?, ?, 'Road trip', clock_timestamp(), clock_timestamp())",
            [$playlistId->toString(), (new PublicId())->toString(), $ownerId->toString()],
        );
        foreach ($entries as $songId => $position) {
            $connection->executeStatement(
                'INSERT INTO playlist_song (id, playlist_id, song_id, position, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
                [Uuid::v7()->toString(), $playlistId->toString(), $songId, $position],
            );
        }

        return $playlistId;
    }

    /** Stores lyrics of the song, as a fetch from LRCLIB does. */
    private function createLyricsFixture(Uuid $songId, string $text, int $lrclibId): Uuid
    {
        $lyricsId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO lyrics (id, song_id, plain_lyrics, source, lrclib_id, created_at, updated_at) VALUES (?, ?, ?, 'lrclib', ?, clock_timestamp(), clock_timestamp())",
            [$lyricsId->toString(), $songId->toString(), $text, $lrclibId],
        );

        return $lyricsId;
    }

    /** Stores the user's favorite of the song, by the song's public ID as Favorites stores it. */
    private function createSongFavoriteFixture(Uuid $userId, Uuid $songId): Uuid
    {
        $favoriteId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at) SELECT ?, ?, ?, 'song', public_id, clock_timestamp(), clock_timestamp() FROM songs WHERE id = ?",
            [$favoriteId->toString(), (new PublicId())->toString(), $userId->toString(), $songId->toString()],
        );

        return $favoriteId;
    }

    /** @return array<string, int> the position of each song the playlist holds, by song ID */
    private function entriesOf(Uuid $playlistId): array
    {
        $entries = [];
        foreach ($this->entityManager->getConnection()->fetchAllNumeric('SELECT song_id, position FROM playlist_song WHERE playlist_id = ? ORDER BY position', [$playlistId->toString()]) as [$songId, $position]) {
            $entries[(string) $songId] = (int) $position;
        }

        return $entries;
    }

    /**
     * @return list<array{string, string, ?string, int, bool, string, ?string, ?string}> ID, song, album, play count, loved flag,
     *                                                                                  last played time, platform and player of each of the user's plays
     */
    private function playsOf(Uuid $userId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], $row[2] === null ? null : (string) $row[2], (int) $row[3], (bool) $row[4], (string) $row[5], $row[6] === null ? null : (string) $row[6], $row[7] === null ? null : (string) $row[7]],
            $this->entityManager->getConnection()->fetchAllNumeric(
                'SELECT id, song_id, album_id, play_count, love, last_played_at, last_platform, last_player FROM media_activities WHERE user_id = ? ORDER BY id',
                [$userId->toString()],
            ),
        );
    }

    /** @return list<string> the public IDs of the songs the user favorited */
    private function songFavoritesOf(Uuid $userId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT entity_public_id FROM user_favorites WHERE user_id = ? AND entity_type = 'song' ORDER BY entity_public_id",
            [$userId->toString()],
        ) |> (static fn (array $songs): array => array_map(strval(...), $songs));
    }

    private function songPublicIdOf(Uuid $songId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM songs WHERE id = ?', [$songId->toString()]);
    }

    /** @return list<string> the public IDs of the albums the user favorited */
    private function albumFavoritesOf(Uuid $userId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT entity_public_id FROM user_favorites WHERE user_id = ? AND entity_type = 'album' ORDER BY entity_public_id",
            [$userId->toString()],
        ) |> (static fn (array $albums): array => array_map(strval(...), $albums));
    }

    /** The album the play counts for, as PostgreSQL holds it. */
    private function playAlbumOf(Uuid $playId): ?string
    {
        $album = $this->entityManager->getConnection()->fetchOne('SELECT album_id FROM media_activities WHERE id = ?', [$playId->toString()]);

        return is_string($album) ? $album : null;
    }

    private function setAutomaticMatching(bool $enabled): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([MetadataSettingDefinitions::AUTO_SYNC => $enabled]);
    }

    private function mergeThroughTheApi(User $admin, Uuid $targetAlbumId, Uuid $sourceAlbumId): Response
    {
        return $this->authenticatedRequest('POST', '/api/albums/merge', $admin, [
            'targetPublicId' => $this->publicIdOf($targetAlbumId),
            'sourcePublicId' => $this->publicIdOf($sourceAlbumId),
        ]);
    }

    private function publicIdOf(Uuid $albumId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM albums WHERE id = ?', [$albumId->toString()]);
    }

    /** @return array{album_id: string, mbid: ?string, match_checked_at: ?string} the song's row as PostgreSQL holds it */
    private function songRow(Uuid $songId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT album_id, mbid, match_checked_at FROM songs WHERE id = ?', [$songId->toString()]);
        $this->assertIsArray($row);

        return $row;
    }

    private function songCount(Uuid $albumId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM songs WHERE album_id = ?', [$albumId->toString()]);
    }

    /** @return list<string> the names of the artists the song credits */
    private function creditsOf(Uuid $songId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT a.name FROM artist_song s JOIN artists a ON a.id = s.artist_id WHERE s.song_id = ? ORDER BY a.name',
            [$songId->toString()],
        );
    }
}
