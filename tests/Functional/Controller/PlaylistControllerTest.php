<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class PlaylistControllerTest extends TestCase
{
    public function testCreatePlaylistRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/playlists/', [
            'name' => 'My Playlist',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testCreatePlaylist(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'My Playlist',
            'description' => 'A test playlist',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('My Playlist', $data['name']);
    }

    public function testIndexReturnsUserPlaylists(): void
    {
        $user = $this->createTestUser();

        $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'Playlist 1',
        ]);

        $response = $this->authenticatedRequest('GET', '/api/playlists/', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
    }

    public function testDeletePlaylist(): void
    {
        $user = $this->createTestUser();

        $createResponse = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'To Delete',
        ]);

        $createdData = json_decode($createResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $createdData['publicId'];

        $deleteResponse = $this->authenticatedRequest('DELETE', '/api/playlists/' . $publicId, $user);
        $this->assertSame(204, $deleteResponse->getStatusCode());
    }

    public function testAddSongPersistsAndShowsSong(): void
    {
        $user = $this->createTestUser();

        // A real song row the playlist_song FK can reference.
        $libraryId = $this->createLibraryFixture();
        $albumId = $this->createAlbumFixture($libraryId, 'Album');
        $songId = $this->createSongFixture($albumId, 'track.mp3', 'hash1', 'Track');

        $createResponse = $this->authenticatedRequest('POST', '/api/playlists/', $user, ['name' => 'With Songs']);
        $this->assertSame(201, $createResponse->getStatusCode());
        $created = json_decode($createResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $created['publicId'];

        $addResponse = $this->authenticatedRequest('POST', '/api/playlists/' . $publicId . '/songs', $user, [
            'songId' => $songId->toString(),
        ]);
        $this->assertJsonResponse($addResponse, 201);

        // Reload via the show endpoint — the song must now be persisted.
        // Pre-fix, save() dropped child writes, so the reloaded songs list was empty.
        $showResponse = $this->authenticatedRequest('GET', '/api/playlists/' . $publicId, $user);
        $show = $this->assertJsonResponse($showResponse, 200, 'data');

        $this->assertIsArray($show['data']['songs']);
        $this->assertCount(1, $show['data']['songs']);
        $this->assertSame($songId->toString(), $show['data']['songs'][0]['uuid']);
    }

    public function testUpdateMetadataKeepsSongs(): void
    {
        $user = $this->createTestUser();

        $libraryId = $this->createLibraryFixture();
        $albumId = $this->createAlbumFixture($libraryId, 'Album');
        $songId = $this->createSongFixture($albumId, 'track.mp3', 'hash1', 'Track');

        $createResponse = $this->authenticatedRequest('POST', '/api/playlists/', $user, ['name' => 'Original']);
        $created = json_decode($createResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $created['publicId'];

        $this->authenticatedRequest('POST', '/api/playlists/' . $publicId . '/songs', $user, [
            'songId' => $songId->toString(),
        ]);

        // Metadata-only update via findByPublicId (loaded without songs in the old
        // code path). save() must not wipe the persisted songs.
        $this->authenticatedRequest('PATCH', '/api/playlists/' . $publicId, $user, [
            'name' => 'Renamed',
        ]);

        $showResponse = $this->authenticatedRequest('GET', '/api/playlists/' . $publicId, $user);
        $show = $this->assertJsonResponse($showResponse, 200, 'data');

        $this->assertSame('Renamed', $show['data']['name']);
        $this->assertCount(1, $show['data']['songs'], 'Metadata update must not drop persisted songs.');
    }

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

    private function createSongFixture(Uuid $albumId, string $path, string $hash, string $title): Uuid
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

        return $songId;
    }
}
