<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

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
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
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

    public function testConsoleMergeMatchesTheApiMerge(): void
    {
        $user = $this->createAdminUser();
        $outcomes = [];

        foreach (['api', 'console'] as $path) {
            $libraryId = $this->createLibraryFixture();
            $targetAlbumId = $this->createAlbumFixture($libraryId, 'Target Album');
            $sourceAlbumId = $this->createAlbumFixture($libraryId, 'Source Album');
            $this->createSongFixture($targetAlbumId, $path . '-1.mp3', $path . '-shared', 'Song 1');
            $this->createSongFixture($sourceAlbumId, $path . '-1-dup.mp3', $path . '-shared', 'Song 1 Duplicate');
            $this->createSongFixture($sourceAlbumId, $path . '-2.mp3', $path . '-2', 'Song 2');
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
            ];
        }

        $this->assertSame(['sourceDeleted' => true, 'songs' => 2, 'mergedFrom' => [[true, 'Source Album']]], $outcomes['api']);
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
