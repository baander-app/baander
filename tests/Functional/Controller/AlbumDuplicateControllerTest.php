<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Library\Application\Port\LibraryAccessPortInterface;
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

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $this->libraryPublicId($libraryId), $user);

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

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $this->libraryPublicId($libraryId), $user);

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

        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $this->libraryPublicId($libraryId), $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
    }

    // --- Album-specific duplicates endpoint ---

    public function testALibraryMemberCanReadAnAlbumsDuplicates(): void
    {
        $user = $this->createTestUser();
        $libraryId = $this->createLibraryFixture();
        static::getContainer()->get(LibraryAccessPortInterface::class)->grant($user->getId(), $libraryId);

        $albumIds = $this->createDuplicateAlbumsFixture($libraryId);
        $album = $this->albumRepository->findByUuid($albumIds[0]);

        $this->assertNotNull($album);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $album->getPublicId()->toString() . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        // The groups' contents are covered by the next test; this one checks member access.
        $this->assertTrue(array_is_list($data['data']));
    }

    public function testAlbumDuplicatesListTheGroupsWithTheAlbumsTheBannerMerges(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        $albumIds = $this->createDuplicateAlbumsFixture($libraryId);
        // Give the second album every artist of the first, so their artists overlap enough to match.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name)
             SELECT gen_random_uuid(), artist_id, ?, role, credited_name FROM artist_album a
             WHERE a.album_id = ? AND NOT EXISTS (SELECT 1 FROM artist_album b WHERE b.album_id = ? AND b.artist_id = a.artist_id)',
            [$albumIds[1]->toString(), $albumIds[0]->toString(), $albumIds[1]->toString()],
        );
        $viewed = $this->albumRepository->findByUuid($albumIds[0]);
        $duplicate = $this->albumRepository->findByUuid($albumIds[1]);
        $this->assertNotNull($viewed);
        $this->assertNotNull($duplicate);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $viewed->getPublicId()->toString() . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data')['data'];
        $this->assertTrue(array_is_list($data));
        $this->assertCount(1, $data);
        $albums = array_column($data[0]['albums'], 'title', 'publicId');
        ksort($albums);
        $expected = [
            $viewed->getPublicId()->toString() => 'The Great Album',
            $duplicate->getPublicId()->toString() => 'The Great Album!',
        ];
        ksort($expected);
        $this->assertSame($expected, $albums);
    }

    public function testAlbumsCreditedUnderOneNamePairEvenWhenTheirArtistsAreNamedApart(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        [$first, $second] = $this->createDisturbedAlbumsFixture($libraryId);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $this->albumPublicId($first) . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data')['data'];
        $this->assertCount(1, $data);
        $artists = array_column($data[0]['albums'], 'artists', 'publicId');
        ksort($artists);
        $expected = [
            $this->albumPublicId($first) => [['name' => 'Disturbed', 'role' => 'primary']],
            $this->albumPublicId($second) => [['name' => 'Disturbed', 'role' => 'primary']],
        ];
        ksort($expected);
        $this->assertSame($expected, $artists);
    }

    public function testTheAlbumResourceListsTheNameTheAlbumIsCreditedUnder(): void
    {
        $user = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        [, $second] = $this->createDisturbedAlbumsFixture($libraryId);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $this->albumPublicId($second), $user);

        $data = $this->assertJsonResponse($response, 200, 'data')['data'];
        $this->assertSame([['name' => 'Disturbed', 'role' => 'primary']], $data['artists']);
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
        static::getContainer()->get(LibraryAccessPortInterface::class)->grant($user->getId(), $libraryId);

        $albumId = $this->createSingleAlbumFixture($libraryId, 'Unique Album Title');
        $album = $this->albumRepository->findByUuid($albumId);

        $this->assertNotNull($album);

        $response = $this->authenticatedRequest('GET', '/api/albums/' . $album->getPublicId()->toString() . '/duplicates', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
    }

    public function testAlbumDuplicatesReturns404WithoutLibraryAccess(): void
    {
        $user = $this->createTestUser();
        $libraryId = $this->createLibraryFixture();
        $albumIds = $this->createDuplicateAlbumsFixture($libraryId);
        $album = $this->albumRepository->findByUuid($albumIds[0]);
        $this->assertNotNull($album);

        $response = $this->authenticatedRequest(
            'GET',
            '/api/albums/' . $album->getPublicId()->toString() . '/duplicates',
            $user,
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- Fixture helpers ---

    private function libraryPublicId(Uuid $id): string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM libraries WHERE id = ?', [$id->toString()]);
        self::assertIsString($value);
        return $value;
    }

    public function testInternalLibraryUuidIsRejected(): void
    {
        $admin = $this->createAdminUser();
        $libraryId = $this->createLibraryFixture();
        $response = $this->authenticatedRequest('GET', '/api/admin/albums/duplicates?libraryId=' . $libraryId->toString(), $admin);
        self::assertSame(400, $response->getStatusCode());
    }

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
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist1Id->toString(), $album1Id->toString(), null, 'Artist One'],
        );
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist2Id->toString(), $album1Id->toString(), null, 'Artist Two'],
        );

        // Album 2: Artist 1, Artist 3 (50% overlap with Album 1 via Artist 1)
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist1Id->toString(), $album2Id->toString(), null, 'Artist One'],
        );
        $conn->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toString(), $artist3Id->toString(), $album2Id->toString(), null, 'Artist Three'],
        );

        return [$album1Id, $album2Id];
    }

    /**
     * Two copies of one album, each credited as "Disturbed" to a different
     * artist: one named "Disturbed", one renamed "Disturbed (US)" since.
     *
     * @return array{Uuid, Uuid} album IDs, the second credited to the renamed artist
     */
    private function createDisturbedAlbumsFixture(Uuid $libraryId): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn = $this->entityManager->getConnection();

        $albumIds = [];
        foreach (['Disturbed', 'Disturbed (US)'] as $artistName) {
            $artistId = Uuid::v7();
            $albumId = Uuid::v7();
            $conn->executeStatement(
                'INSERT INTO artists (id, public_id, name, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$artistId->toString(), (new PublicId())->toString(), $artistName, 'group', $now, $now],
            );
            $conn->executeStatement(
                'INSERT INTO albums (id, public_id, library_id, title, type, mbid, year, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$albumId->toString(), (new PublicId())->toString(), $libraryId->toString(), 'Ten Thousand Fists', 'album', null, 2005, '{}', $now, $now],
            );
            $conn->executeStatement(
                'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
                [Uuid::v7()->toString(), $artistId->toString(), $albumId->toString(), 'primary', 'Disturbed'],
            );
            $albumIds[] = $albumId;
        }

        return [$albumIds[0], $albumIds[1]];
    }

    private function albumPublicId(Uuid $id): string
    {
        $album = $this->albumRepository->findByUuid($id);
        $this->assertNotNull($album);

        return $album->getPublicId()->toString();
    }
}
