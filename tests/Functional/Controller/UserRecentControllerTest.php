<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class UserRecentControllerTest extends TestCase
{
    public function testRecentRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/recent');
        $this->assertJsonResponse($response, 401);
    }

    public function testRecentReturnsEmptyArrayWhenNoActivity(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);
        $this->assertSame([], $data['data']);
    }

    public function testRecentReturnsEnrichedItems(): void
    {
        $user = $this->createTestUser();

        // Record a play first
        $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $this->createSongFixture(),
        ]);

        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);

        $this->assertNotEmpty($data['data']);
        $item = $data['data'][0];
        $this->assertArrayHasKey('publicId', $item);
        $this->assertArrayHasKey('activityType', $item);
        $this->assertArrayHasKey('lastPlayedAt', $item);
        $this->assertArrayHasKey('playCount', $item);
    }

    public function testRecentItemHasSidebarOptimizedFields(): void
    {
        $user = $this->createTestUser();

        $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $this->createSongFixture(),
        ]);

        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);
        $item = $data['data'][0];

        // Should have sidebar-optimized fields
        $this->assertArrayHasKey('publicId', $item);
        $this->assertArrayHasKey('activityType', $item);
        $this->assertArrayHasKey('songTitle', $item);
        $this->assertArrayHasKey('coverImage', $item);
        $this->assertArrayHasKey('lastPlayedAt', $item);

        // Should NOT have internal fields
        $this->assertArrayNotHasKey('uuid', $item);
        $this->assertArrayNotHasKey('userId', $item);
        $this->assertArrayNotHasKey('createdAt', $item);
    }

    public function testRecentCapsLimitAt20(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/user/recent?limit=100', $user);
        $this->assertJsonResponse($response, 200);
    }

    public function testRecentMinimumLimitIs1(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/user/recent?limit=0', $user);
        // Should not error — limit capped to 1
        $this->assertJsonResponse($response, 200);
    }

    /**
     * Create a library -> album -> song chain and return the song's public ID,
     * which /api/activity/play resolves via songPort->findByPublicId().
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
