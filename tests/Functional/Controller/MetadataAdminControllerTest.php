<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class MetadataAdminControllerTest extends TestCase
{
    // --- Sync Status ---

    public function testSyncStatusReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('lastSyncAt', $data['data']);
        $this->assertArrayHasKey('totalTracks', $data['data']);
        $this->assertArrayHasKey('syncedTracks', $data['data']);
        $this->assertArrayHasKey('pendingTracks', $data['data']);
        $this->assertArrayHasKey('failedTracks', $data['data']);
        $this->assertArrayHasKey('sources', $data['data']);
        $this->assertIsInt($data['data']['totalTracks']);
        $this->assertIsInt($data['data']['syncedTracks']);
        $this->assertIsArray($data['data']['sources']);
    }

    public function testSyncStatusReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSyncStatusReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Trigger Sync ---

    public function testTriggerSyncReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user, []);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsDispatched', $data['data']);
        $this->assertIsInt($data['data']['jobsDispatched']);
    }

    public function testTriggerSyncWithGenreSource(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user, [
            'source' => 'genres',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsDispatched', $data['data']);
    }

    public function testTriggerSyncReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Providers ---

    public function testProvidersReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertNotEmpty($data['data']);

        $provider = $data['data'][0];
        $this->assertArrayHasKey('name', $provider);
        $this->assertArrayHasKey('enabled', $provider);
        $this->assertArrayHasKey('configured', $provider);
    }

    public function testProvidersReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $user);

        $this->assertSame(403, $response->getStatusCode());
    }
}
