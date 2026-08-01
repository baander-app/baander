<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class RecommendationAdminControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /api/admin/recommendations/coverage
    // ---------------------------------------------------------------

    public function testAdminCanGetCoverage(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/coverage', $admin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('total_tracks', $data['data']);
        $this->assertArrayHasKey('tracks_with_recommendations', $data['data']);
        $this->assertArrayHasKey('tracks_without_recommendations', $data['data']);
        $this->assertArrayHasKey('coverage_percentage', $data['data']);
    }

    public function testRegularUserCannotGetCoverage(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/coverage', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // GET /api/admin/recommendations/source-quality
    // ---------------------------------------------------------------

    public function testAdminCanGetSourceQuality(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/source-quality', $admin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('by_source_type', $data['data']);
        $this->assertArrayHasKey('avg_confidence_score', $data['data']);
        $this->assertIsArray($data['data']['by_source_type']);
        $this->assertIsNumeric($data['data']['avg_confidence_score']);
    }

    public function testRegularUserCannotGetSourceQuality(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/source-quality', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // GET /api/admin/recommendations/freshness
    // ---------------------------------------------------------------

    public function testAdminCanGetFreshness(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/freshness', $admin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('avg_age_seconds', $data['data']);
        $this->assertArrayHasKey('last_generated_at', $data['data']);
        $this->assertIsNumeric($data['data']['avg_age_seconds']);
    }

    public function testRegularUserCannotGetFreshness(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/recommendations/freshness', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // All endpoints return 403 for unauthenticated users
    // ---------------------------------------------------------------

    public function testAnonymousCannotAccessCoverage(): void
    {
        $response = $this->anonymousRequest('GET', '/api/admin/recommendations/coverage');
        // 401 because no auth header, not 403
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}
