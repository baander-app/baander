<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class AlbumMergeTest extends TestCase
{
    private AlbumRepositoryInterface $albumRepository;
    private SongRepositoryInterface $songRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->albumRepository = $container->get(AlbumRepositoryInterface::class);
        $this->songRepository = $container->get(SongRepositoryInterface::class);
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
        $this->assertSame($targetAlbumId->toString(), $data['data']['uuid']);

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

    // --- Fixture helpers ---

    private function createLibraryFixture(): Uuid
    {
        $libraryId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
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

    private function createSongFixture(Uuid $albumId, string $path, string $hash, string $title): void
    {
        $songId = Uuid::v7();
        $publicId = new PublicId();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, path, hash, title, size, mime_type, length, track, disc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $songId->toString(),
                $publicId->toString(),
                $albumId->toString(),
                $path,
                $hash,
                $title,
                1000,
                'audio/mpeg',
                180.0,
                1,
                1,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );
    }
}
