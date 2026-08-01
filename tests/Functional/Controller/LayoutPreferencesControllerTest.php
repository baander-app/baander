<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for layout-preferences management.
 *
 * Covers LayoutPreferencesController:
 *   GET    /api/user/layout-preferences/          get (404 when absent)
 *   PUT    /api/user/layout-preferences/          save (versioned)
 *   GET    /api/user/layout-preferences/history   version history
 *   POST   /api/user/layout-preferences/rollback  restore a previous version
 *
 * Same versioned shape as AudioPreferences — the adapter has no server-side
 * optimistic locking (saveForUser never throws).
 */
final class LayoutPreferencesControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/layout-preferences/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsNotFoundWhenAbsent(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user);

        $this->assertJsonResponse($response, 404);
    }

    public function testIndexReturnsSavedPreferences(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('expanded', 'albums');

        $this->savePreferences($user, $payload, 0);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user),
            200,
            'data',
        );

        $this->assertSame($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    // ---------------------------------------------------------------
    // PUT /
    // ---------------------------------------------------------------

    public function testSaveRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/layout-preferences/', [
            'payload' => ['mode' => 'grid'],
            'version' => 0,
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testSaveCreatesPreferencesWithVersionOne(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('compact', 'library');

        $data = $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200, 'data');

        $this->assertSame($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    public function testSaveIncrementsVersion(): void
    {
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'library'), 0), 200, 'data');
        $this->assertSame(1, $first['data']['version']);

        $second = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('compact', 'library'), 1), 200, 'data');
        $this->assertSame(2, $second['data']['version']);
    }

    public function testSaveDoesNotEnforceOptimisticLocking(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, $this->validPayload('expanded', 'library'), 0);   // → version 1
        $stale = $this->savePreferences($user, $this->validPayload('compact', 'library'), 0); // stale version 0

        $this->assertSame(200, $stale->getStatusCode(), 'A stale version must not be rejected (no optimistic locking).');
    }

    public function testSaveWithNegativeVersionFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->savePreferences($user, $this->validPayload('expanded', 'library'), -1);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // GET /history
    // ---------------------------------------------------------------

    public function testHistoryRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/layout-preferences/history');

        $this->assertJsonResponse($response, 401);
    }

    public function testHistoryReturnsSnapshotsForEachSave(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, $this->validPayload('expanded', 'library'), 0);
        $this->savePreferences($user, $this->validPayload('compact', 'library'), 1);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/history', $user),
            200,
            'data',
        );

        $this->assertGreaterThanOrEqual(2, count($data['data']['history']));
    }

    // ---------------------------------------------------------------
    // POST /rollback
    // ---------------------------------------------------------------

    public function testRollbackRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/user/layout-preferences/rollback', ['version' => 1]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRollbackRestoresPreviousVersionPayload(): void
    {
        $user = $this->createTestUser();
        $firstPayload = $this->validPayload('expanded', 'library');
        $secondPayload = $this->validPayload('compact', 'library');

        $this->savePreferences($user, $firstPayload, 0);   // version 1
        $this->savePreferences($user, $secondPayload, 1);  // version 2

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/user/layout-preferences/rollback', $user, ['version' => 1]),
            200,
            'data',
        );

        $this->assertSame($firstPayload, $data['data']['payload'], 'Rollback must restore the version-1 payload.');
    }

    public function testRollbackWithVersionBelowOneFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/user/layout-preferences/rollback', $user, ['version' => 0]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function validPayload(string $mode = 'expanded', string $activeTab = 'library'): array
    {
        return ['mode' => $mode, 'activeTab' => $activeTab];
    }

    private function savePreferences(User $user, array $payload, int $version)
    {
        return $this->authenticatedRequest('PUT', '/api/user/layout-preferences/', $user, [
            'payload' => $payload,
            'version' => $version,
        ]);
    }
}
