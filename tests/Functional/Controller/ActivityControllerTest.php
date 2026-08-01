<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class ActivityControllerTest extends TestCase
{
    public function testPlayRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/activity/play', [
            'songId' => (new \App\Shared\Domain\Model\PublicId())->toString(),
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRecordPlay(): void
    {
        $user = $this->createTestUser();
        $songId = $this->createSongFixture();

        $response = $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $songId,
            'platform' => 'web',
            'player' => 'browser',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertArrayHasKey('songId', $data['data']);
    }

    public function testHistoryReturnsEmptyList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/activity/history', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
    }

    public function testLovedRequiresAuth(): void
    {
        $response = $this->anonymousRequest('GET', '/api/activity/loved');

        $this->assertJsonResponse($response, 401);
    }

    public function testRecordPlayAndToggleLove(): void
    {
        $user = $this->createTestUser();
        $songId = $this->createSongFixture();

        // Record a play
        $playResponse = $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $songId,
            'platform' => 'web',
            'player' => 'browser',
        ]);
        $playData = json_decode($playResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $playData['data']['publicId'];

        // Toggle love
        $loveResponse = $this->authenticatedRequest('POST', '/api/activity/love/' . $publicId, $user);
        $loveData = $this->assertJsonResponse($loveResponse, 200, 'data');
        $this->assertTrue($loveData['data']['love']);
    }

    /**
     * Create a library -> album -> song chain and return the song's public ID,
     * which the /play endpoint resolves via songPort->findByPublicId().
     */
    private function createSongFixture(): string
    {
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $songId = Uuid::v7();
        $songPublicId = new PublicId();
        $now = new \DateTimeImmutable();
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$libraryId->toString(), 'lib-' . bin2hex(random_bytes(4)), 'Test Library', '/tmp/test-' . bin2hex(random_bytes(4)), 'music', 0, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, year, locked_fields, merged_from, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$albumId->toString(), (new PublicId())->toString(), $libraryId->toString(), 'Album', 'studio', 2023, '{}', '[]', $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );
        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, path, hash, title, size, mime_type, length, track, disc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$songId->toString(), $songPublicId->toString(), $albumId->toString(), 'track.mp3', 'hash1', 'Track', 1000, 'audio/mpeg', 180.0, 1, 1, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );

        return $songPublicId->toString();
    }
}
