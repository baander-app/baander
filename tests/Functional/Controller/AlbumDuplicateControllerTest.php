<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class AlbumDuplicateControllerTest extends TestCase
{
    private AlbumRepositoryInterface $albumRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->albumRepository = $container->get(AlbumRepositoryInterface::class);
    }

    // --- Admin duplicates listing endpoint ---

    public function testListDuplicatesReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        $this->createDuplicateAlbumsFixture($libraryId);

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $libraryId->toString(), $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);

        if (!empty($data['data'])) {
            $group = $data['data'][0];
            $this->assertArrayHasKey('albumIds', $group);
            $this->assertArrayHasKey('confidence', $group);
            $this->assertArrayHasKey('albumCount', $group);
            $this->assertIsArray($group['albumIds']);
            $this->assertIsFloat($group['confidence']);
            $this->assertIsInt($group['albumCount']);
            $this->assertGreaterThanOrEqual(2, $group['albumCount']);
        }
    }

    public function testListDuplicatesReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $libraryId = $this->createLibraryFixture();

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $libraryId->toString(), $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListDuplicatesReturns400ForMissingLibraryId(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates', $user);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testListDuplicatesReturns400ForInvalidLibraryId(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=invalid-uuid', $user);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testListDuplicatesReturnsEmptyArrayForLibraryWithNoDuplicates(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();

        $this->createSingleAlbumFixture($libraryId, 'Unique Album');

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $libraryId->toString(), $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
    }

    // --- Album-specific duplicates endpoint ---

    public function testAlbumDuplicatesReturns200(): void
    {
        $user = $this->createTestUser();
        $libraryId = $this->createLibraryFixture();

        $albumIds = $this->createDuplicateAlbumsFixture($libraryId);
        $album = $this->albumRepository->findByUuid($albumIds[0]);

        $this->assertNotNull($album);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $album->getPublicId()->toString() . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);

        if (!empty($data['data'])) {
            $group = $data['data'][0];
            $this->assertArrayHasKey('albumIds', $group);
            $this->assertArrayHasKey('confidence', $group);
            $this->assertArrayHasKey('albumCount', $group);
            $this->assertContains($album->getPublicId()->toString(), $group['albumIds']);
        }
    }

    public function testAlbumDuplicatesReturns404ForInvalidPublicId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/albums/invalid-public-id/duplicates', $user);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAlbumDuplicatesReturns404ForNonExistentAlbum(): void
    {
        $user = $this->createTestUser();

        $fakePublicId = new PublicId();
        $response = $this->authenticatedRequest('GET', '/api/albums/' . $fakePublicId->toString() . '/duplicates', $user);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAlbumDuplicatesReturnsEmptyArrayForAlbumWithNoDuplicates(): void
    {
        $user = $this->createTestUser();
        $libraryId = $this->createLibraryFixture();

        $albumId = $this->createSingleAlbumFixture($libraryId, 'Unique Album Title');
        $album = $this->albumRepository->findByUuid($albumId);

        $this->assertNotNull($album);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $album->getPublicId()->toString() . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
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

    private function createSingleAlbumFixture(Uuid $libraryId, string $title): Uuid
    {
        $albumId = Uuid::v7();
        $publicId = new PublicId();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, year, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                $publicId->toString(),
                $libraryId->toString(),
                $title,
                'studio',
                null,
                2023,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $albumId;
    }

    /**
     * Creates a set of albums that should be detected as duplicates.
     * Similar titles, same year, overlapping artists.
     *
     * @return Uuid[] Array of album IDs
     */
    private function createDuplicateAlbumsFixture(Uuid $libraryId): array
    {
        $now = new \DateTimeImmutable();
        $conn = $this->entityManager->getConnection();

        // Create two artists
        $artist1Id = Uuid::v7();
        $artist2Id = Uuid::v7();
        $artist3Id = Uuid::v7();

        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$artist1Id->toString(), new PublicId()->toString(), 'Artist One', 'person', $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$artist2Id->toString(), new PublicId()->toString(), 'Artist Two', 'person', $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$artist3Id->toString(), new PublicId()->toString(), 'Artist Three', 'person', $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );

        // Create two albums with similar titles (85%+ Levenshtein similarity)
        $album1Id = Uuid::v7();
        $album2Id = Uuid::v7();
        $album1PublicId = new PublicId();
        $album2PublicId = new PublicId();

        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, year, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $album1Id->toString(),
                $album1PublicId->toString(),
                $libraryId->toString(),
                'The Great Album',
                'studio',
                null,
                2023,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, year, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $album2Id->toString(),
                $album2PublicId->toString(),
                $libraryId->toString(),
                'The Great Album!', // Very similar title
                'studio',
                null,
                2023,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        // Link artists to albums (50% overlap for Jaccard)
        // Album 1: Artist 1, Artist 2
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist1Id->toString(), $album1Id->toString(), null],
        );
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist2Id->toString(), $album1Id->toString(), null],
        );

        // Album 2: Artist 1, Artist 3 (50% overlap with Album 1 via Artist 1)
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist1Id->toString(), $album2Id->toString(), null],
        );
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist3Id->toString(), $album2Id->toString(), null],
        );

        return [$album1Id, $album2Id];
    }
}
