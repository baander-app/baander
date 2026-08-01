<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for player-preferences management.
 *
 * Covers PlayerPreferencesController:
 *   GET    /api/user/player-preferences/          get (404 when absent)
 *   PUT    /api/user/player-preferences/          save (versioned)
 *   GET    /api/user/player-preferences/history   version history
 *   POST   /api/user/player-preferences/rollback  restore a previous version
 *
 * Same versioned shape as AudioPreferences/LayoutPreferences.
 */
final class PlayerPreferencesControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/player-preferences/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsNotFoundWhenAbsent(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/player-preferences/', $user);

        $this->assertJsonResponse($response, 404);
    }

    public function testIndexReturnsSavedPreferences(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload(0.8, 'off');

        $this->savePreferences($user, $payload, 0);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/player-preferences/', $user),
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
        $response = $this->anonymousRequest('PUT', '/api/user/player-preferences/', [
            'payload' => ['volume' => 50],
            'version' => 0,
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testSaveCreatesPreferencesWithVersionOne(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload(0.5, 'all');

        $data = $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200, 'data');

        $this->assertSame($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    public function testSaveIncrementsVersion(): void
    {
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload(0.5), 0), 200, 'data');
        $this->assertSame(1, $first['data']['version']);

        $second = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload(0.8), 1), 200, 'data');
        $this->assertSame(2, $second['data']['version']);
    }

    public function testSaveDoesNotEnforceOptimisticLocking(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, $this->validPayload(0.5), 0);
        $stale = $this->savePreferences($user, $this->validPayload(0.8), 0);

        $this->assertSame(200, $stale->getStatusCode(), 'A stale version must not be rejected (no optimistic locking).');
    }

    public function testSaveWithNegativeVersionFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->savePreferences($user, $this->validPayload(0.5), -1);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // GET /history
    // ---------------------------------------------------------------

    public function testHistoryRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/player-preferences/history');

        $this->assertJsonResponse($response, 401);
    }

    public function testHistoryReturnsSnapshotsForEachSave(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, $this->validPayload(0.5), 0);
        $this->savePreferences($user, $this->validPayload(0.8), 1);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/player-preferences/history', $user),
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
        $response = $this->anonymousRequest('POST', '/api/user/player-preferences/rollback', ['version' => 1]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRollbackRestoresPreviousVersionPayload(): void
    {
        $user = $this->createTestUser();
        $firstPayload = $this->validPayload(0.5);
        $secondPayload = $this->validPayload(0.8);

        $this->savePreferences($user, $firstPayload, 0);   // version 1
        $this->savePreferences($user, $secondPayload, 1);  // version 2

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/user/player-preferences/rollback', $user, ['version' => 1]),
            200,
            'data',
        );

        $this->assertSame($firstPayload, $data['data']['payload'], 'Rollback must restore the version-1 payload.');
    }

    public function testRollbackWithVersionBelowOneFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/user/player-preferences/rollback', $user, ['version' => 0]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function validPayload(float $volume = 0.8, string $repeat = 'off'): array
    {
        return [
            'shuffle' => false,
            'repeat' => $repeat,
            'volume' => $volume,
            'muted' => false,
            'crossfadeEnabled' => false,
            'crossfadeDuration' => 5.5,
            'replayGainEnabled' => false,
            'replayGainMode' => 'track',
            'replayGainPreAmp' => 0.5,
        ];
    }

    private function savePreferences(User $user, array $payload, int $version)
    {
        return $this->authenticatedRequest('PUT', '/api/user/player-preferences/', $user, [
            'payload' => $payload,
            'version' => $version,
        ]);
    }
}
