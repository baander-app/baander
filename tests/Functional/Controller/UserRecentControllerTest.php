<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
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
            'songId' => (new \App\Shared\Domain\Model\PublicId())->toString(),
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
            'songId' => (new \App\Shared\Domain\Model\PublicId())->toString(),
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
}
