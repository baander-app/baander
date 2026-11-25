<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $libraryId = $this->createLibraryFixture($user->getId());
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
        $this->assertSame($songId->toString(), $show['data']['songs'][0]['publicId']);
    }

    public function testUpdateMetadataKeepsSongs(): void
    {
        $user = $this->createTestUser();

        $libraryId = $this->createLibraryFixture($user->getId());
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

    public function testAThreeMillionCharacterDescriptionIsRefusedAndNothingIsStored(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'Oversized',
            'description' => str_repeat('a', 3_000_000),
        ]);

        $error = $this->assertJsonResponse($response, 422, 'error');
        $this->assertArrayHasKey('description', $error['error']['details']);
        $this->assertSame(0, $this->ownedPlaylists($user->getId()));
    }

    public function testRenamingToA256CharacterNameIsRefused(): void
    {
        $user = $this->createTestUser();
        $publicId = $this->createPlaylist($user, 'Original');

        $response = $this->authenticatedRequest('PATCH', '/api/playlists/' . $publicId, $user, [
            'name' => str_repeat('a', 256),
        ]);

        $this->assertJsonResponse($response, 422);
        $show = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/playlists/' . $publicId, $user), 200, 'data');
        $this->assertSame('Original', $show['data']['name']);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function refusedSmartRules(): iterable
    {
        yield '51 rules' => [array_fill(0, 51, ['field' => 'genre', 'operator' => 'equals', 'value' => 'Jazz'])];
        yield 'unknown field' => [[['field' => 'path', 'operator' => 'contains', 'value' => '/']]];
    }

    /** @param list<array<string, mixed>> $rules */
    #[DataProvider('refusedSmartRules')]
    public function testSmartRulesOutsideTheEditorsShapeAreRefused(array $rules): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'Smart',
            'isSmart' => true,
            'smartRules' => $rules,
        ]);

        $this->assertJsonResponse($response, 422);
        $this->assertSame(0, $this->ownedPlaylists($user->getId()));
    }

    public function testTheThousandAndFirstPlaylistIsRefused(): void
    {
        $user = $this->createTestUser();
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO playlists (id, public_id, user_id, name, created_at, updated_at)
                SELECT gen_random_uuid(), 'cap' || lpad(i::text, 18, '0'), ?, 'Playlist ' || i, now(), now()
                FROM generate_series(1, 999) AS i
                SQL,
            [$user->getId()->toString()],
        );

        $this->createPlaylist($user, 'The thousandth');
        $response = $this->authenticatedRequest('POST', '/api/playlists/', $user, ['name' => 'One too many']);

        $error = $this->assertJsonResponse($response, 409, 'error');
        $this->assertSame(['reason' => 'playlist_limit', 'limit' => 1000], $error['error']['details']);
        $this->assertSame(1000, $this->ownedPlaylists($user->getId()));
    }

    public function testTheFiftyFirstCollaboratorIsRefused(): void
    {
        $owner = $this->createTestUser();
        $publicId = $this->createPlaylist($owner, 'Shared');
        $connection = $this->entityManager->getConnection();
        $playlistId = $connection->fetchOne('SELECT id FROM playlists WHERE public_id = ?', [$publicId]);
        $connection->executeStatement(
            <<<'SQL'
                WITH collaborators AS (
                    INSERT INTO users (id, public_id, name, email, password, created_at, updated_at)
                    SELECT gen_random_uuid(), 'col' || lpad(i::text, 18, '0'), 'Collaborator ' || i,
                           'collaborator-' || i || '@baander.app', 'hash', now(), now()
                    FROM generate_series(1, 50) AS i
                    RETURNING id
                )
                INSERT INTO playlist_collaborators (id, playlist_id, user_id, created_at, updated_at)
                SELECT gen_random_uuid(), ?, id, now(), now() FROM collaborators
                SQL,
            [$playlistId],
        );
        $fiftyFirst = $this->createTestUser('fifty-first@baander.app');

        $response = $this->authenticatedRequest('PUT', '/api/playlists/' . $publicId . '/collaborators/' . $fiftyFirst->getPublicId()->toString(), $owner);

        $error = $this->assertJsonResponse($response, 409, 'error');
        $this->assertSame(['reason' => 'collaborator_limit', 'limit' => 50], $error['error']['details']);
        $this->assertSame(50, (int) $connection->fetchOne('SELECT count(*) FROM playlist_collaborators WHERE playlist_id = ?', [$playlistId]));
    }

    public function testAPlaylistStoredBeforeItsBoundsStaysReadableAndDeletable(): void
    {
        $user = $this->createTestUser();
        $publicId = $this->createPlaylist($user, 'Stored earlier');
        $description = str_repeat('d', 5000);
        $connection = $this->entityManager->getConnection();
        // Recreates the state the migration leaves: a row over the bound under a NOT VALID check.
        $connection->executeStatement('ALTER TABLE playlists DROP CONSTRAINT chk_playlists_description_length');
        $connection->executeStatement('UPDATE playlists SET description = ? WHERE public_id = ?', [$description, $publicId]);
        $connection->executeStatement('ALTER TABLE playlists ADD CONSTRAINT chk_playlists_description_length CHECK (char_length(description) <= 2000) NOT VALID');

        $show = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/playlists/' . $publicId, $user), 200, 'data');
        $this->assertSame($description, $show['data']['description']);
        $index = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/playlists/', $user), 200, 'data');
        $this->assertSame($description, $index['data'][0]['description']);

        $this->assertSame(204, $this->authenticatedRequest('DELETE', '/api/playlists/' . $publicId, $user)->getStatusCode());
        $this->assertSame(0, $this->ownedPlaylists($user->getId()));
    }

    private function createPlaylist(User $user, string $name): string
    {
        $created = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/playlists/', $user, ['name' => $name]), 201);

        return $created['publicId'];
    }

    private function ownedPlaylists(Uuid $userId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM playlists WHERE user_id = ?',
            [$userId->toString()],
        );
    }

    private function createLibraryFixture(Uuid $userId): Uuid
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
                $now->format('Y-m-d H:i:s.uP'),
                $now->format('Y-m-d H:i:s.uP'),
            ],
        );

        $access = static::getContainer()->get(LibraryAccessPortInterface::class);
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->grant($userId, $libraryId);

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
                $now->format('Y-m-d H:i:s.uP'),
                $now->format('Y-m-d H:i:s.uP'),
            ],
        );

        return $albumId;
    }

    private function createSongFixture(Uuid $albumId, string $path, string $hash, string $title): PublicId
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
                $now->format('Y-m-d H:i:s.uP'),
                $now->format('Y-m-d H:i:s.uP'),
            ],
        );

        return $publicId;
    }
}
