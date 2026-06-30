<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class LyricsAdminControllerTest extends TestCase
{
    // --- Coverage ---

    public function testCoverageReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('totalTracks', $data['data']);
        $this->assertArrayHasKey('tracksWithLyrics', $data['data']);
        $this->assertArrayHasKey('tracksWithoutLyrics', $data['data']);
        $this->assertArrayHasKey('coveragePercentage', $data['data']);
        $this->assertArrayHasKey('bySource', $data['data']);
        $this->assertIsInt($data['data']['totalTracks']);
        $this->assertIsInt($data['data']['tracksWithLyrics']);
        $this->assertIsNumeric($data['data']['coveragePercentage']);
        $this->assertIsArray($data['data']['bySource']);
    }

    public function testCoverageReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCoverageReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Bulk Fetch ---

    public function testBulkFetchReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user, [
            'limit' => 10,
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsEnqueued', $data['data']);
        $this->assertIsInt($data['data']['jobsEnqueued']);
    }

    public function testBulkFetchReturns403ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBulkFetchReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Sync Status ---

    public function testSyncStatusReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('lastSyncAt', $data['data']);
        $this->assertArrayHasKey('recentJobs', $data['data']);
        $this->assertArrayHasKey('failedJobs', $data['data']);
        $this->assertArrayHasKey('completedJobs', $data['data']);
        $this->assertIsInt($data['data']['recentJobs']);
        $this->assertIsInt($data['data']['failedJobs']);
        $this->assertIsInt($data['data']['completedJobs']);
    }

    public function testSyncStatusReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSyncStatusReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $this->assertSame(403, $response->getStatusCode());
    }
}
