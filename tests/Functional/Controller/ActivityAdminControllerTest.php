<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class ActivityAdminControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /api/admin/activity/summary
    // ---------------------------------------------------------------

    public function testSuperAdminCanGetSummary(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('total_plays', $data['data']);
        $this->assertArrayHasKey('unique_tracks', $data['data']);
        $this->assertArrayHasKey('unique_artists', $data['data']);
        $this->assertArrayHasKey('total_listening_time', $data['data']);
    }

    public function testAdminCanGetSummary(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary', $admin);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRegularUserCannotGetSummary(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSummaryWithCustomDateRange(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest(
            'GET',
            '/api/admin/activity/summary?from=2025-01-01&to=2025-12-31',
            $superAdmin,
        );
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertSame(0, $data['data']['total_plays']);
    }

    // ---------------------------------------------------------------
    // GET /api/admin/activity/top-tracks
    // ---------------------------------------------------------------

    public function testSuperAdminCanGetTopTracks(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/top-tracks', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertIsArray($data['data']);
    }

    public function testRegularUserCannotGetTopTracks(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/top-tracks', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // GET /api/admin/activity/top-artists
    // ---------------------------------------------------------------

    public function testSuperAdminCanGetTopArtists(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/top-artists', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertIsArray($data['data']);
    }

    public function testRegularUserCannotGetTopArtists(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/top-artists', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // GET /api/admin/activity/engagement
    // ---------------------------------------------------------------

    public function testSuperAdminCanGetEngagement(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/engagement', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('active_users', $data['data']);
        $this->assertArrayHasKey('avg_plays_per_user', $data['data']);
        $this->assertArrayHasKey('avg_session_length', $data['data']);
    }

    public function testAdminCanGetEngagement(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/engagement', $admin);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRegularUserCannotGetEngagement(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/engagement', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEngagementWithCustomDateRange(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest(
            'GET',
            '/api/admin/activity/engagement?from=2025-01-01&to=2025-12-31',
            $superAdmin,
        );
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertSame(0, $data['data']['active_users']);
        $this->assertEqualsWithDelta(0.0, $data['data']['avg_plays_per_user'], 0.01);
    }
}
