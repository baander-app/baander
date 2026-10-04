<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for audio-preferences management.
 *
 * Covers AudioPreferencesController:
 *   GET    /api/user/audio-preferences/           get (404 when absent)
 *   PUT    /api/user/audio-preferences/           save (versioned)
 *   GET    /api/user/audio-preferences/history    version history
 *   POST   /api/user/audio-preferences/rollback   restore a previous version
 *
 * Saves require the current persisted version and reject stale writes without adding history.
 */
final class AudioPreferencesControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /  (index)
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/audio-preferences/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsNotFoundWhenNoPreferences(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user);

        $this->assertJsonResponse($response, 404);
    }

    public function testIndexReturnsSavedPreferences(): void
    {
        $user = $this->createTestUser();
        $payload = ['eqMode' => 'flat', 'masterGain' => 1.5];

        $this->savePreferences($user, $payload, 0);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user),
            200,
            'data',
        );

        $this->assertEquals($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    // ---------------------------------------------------------------
    // PUT /  (save)
    // ---------------------------------------------------------------

    public function testSaveRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/audio-preferences/', [
            'payload' => ['eqMode' => 'flat'],
            'version' => 0,
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testSaveCreatesPreferencesWithVersionOne(): void
    {
        $user = $this->createTestUser();
        $payload = ['eqMode' => 'simple', 'enabled' => true];

        $data = $this->assertJsonResponse(
            $this->savePreferences($user, $payload, 0),
            200,
            'data',
        );

        $this->assertEquals($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    public function testSaveIncrementsVersion(): void
    {
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], 0), 200, 'data');
        $this->assertSame(1, $first['data']['version']);

        $second = $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'BASS'], 1), 200, 'data');
        $this->assertSame(2, $second['data']['version']);
        $this->assertSame(['preset' => 'BASS'], $second['data']['payload']);
    }

    public function testSaveRejectsStaleVersionWithoutChangingPayloadOrHistory(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, ['preset' => 'FLAT'], 0);   // -> version 1
        $stale = $this->savePreferences($user, ['preset' => 'BASS'], 0); // stale version 0

        $conflict = $this->assertJsonResponse($stale, 409);
        $this->assertSame(1, $conflict['error']['details']['currentVersion']);
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user), 200, 'data');
        $this->assertSame(['preset' => 'FLAT'], $saved['data']['payload']);
        $this->assertSame(1, $saved['data']['version']);
        $history = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/history', $user), 200, 'data');
        $this->assertCount(1, $history['data']['history']);
        $retry = $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], 1), 200, 'data');
        $this->assertSame(2, $retry['data']['version']);
    }

    public function testSaveRejectsNonzeroVersionWhenAbsent(): void
    {
        $user = $this->createTestUser();
        $conflict = $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], 3), 409);
        $this->assertSame(0, $conflict['error']['details']['currentVersion']);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user), 404);
    }

    public function testSaveRejectsStalePositiveAndFutureVersions(): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], 0), 200);
        $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], 1), 200);
        foreach ([1, 9] as $expectedVersion) {
            $conflict = $this->assertJsonResponse($this->savePreferences($user, ['preset' => 'FLAT'], $expectedVersion), 409);
            $this->assertSame(2, $conflict['error']['details']['currentVersion']);
        }
    }

    public function testSaveWithNegativeVersionFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->savePreferences($user, ['eqMode' => 'flat'], -1);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // GET /history
    // ---------------------------------------------------------------

    public function testHistoryRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/audio-preferences/history');

        $this->assertJsonResponse($response, 401);
    }

    public function testHistoryReturnsSnapshotsForEachSave(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, ['preset' => 'FLAT'], 0);
        $this->savePreferences($user, ['preset' => 'BASS'], 1);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/audio-preferences/history', $user),
            200,
            'data',
        );

        $history = $data['data']['history'];
        $this->assertCount(2, $history);
        $versions = array_column($history, 'version');
        $this->assertContains(1, $versions);
        $this->assertContains(2, $versions);
    }

    // ---------------------------------------------------------------
    // POST /rollback
    // ---------------------------------------------------------------

    public function testRollbackRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/user/audio-preferences/rollback', ['version' => 1]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRollbackRestoresPreviousVersionPayload(): void
    {
        $user = $this->createTestUser();
        $firstPayload = ['preset' => 'FLAT'];
        $secondPayload = ['preset' => 'BASS'];

        $this->savePreferences($user, $firstPayload, 0);   // version 1
        $this->savePreferences($user, $secondPayload, 1);  // version 2

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/user/audio-preferences/rollback', $user, ['version' => 1]),
            200,
            'data',
        );

        $this->assertEquals($firstPayload, $data['data']['payload'], 'Rollback must restore the version-1 payload.');
        $this->assertSame(3, $data['data']['version']);
    }

    public function testRollbackWithVersionBelowOneFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/user/audio-preferences/rollback', $user, ['version' => 0]);

        $this->assertJsonResponse($response, 422);
    }

    public function testRollbackToUnknownVersionSurfacesServerError(): void
    {
        $user = $this->createTestUser();

        // rollbackTo() throws InvalidArgumentException for a missing history entry;
        // the global ExceptionSubscriber maps non-HTTP exceptions to 500.
        $response = $this->authenticatedRequest('POST', '/api/user/audio-preferences/rollback', $user, ['version' => 999]);

        $this->assertSame(500, $response->getStatusCode(), $response->getContent());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function savePreferences(User $user, array $payload, int $version)
    {
        return $this->authenticatedRequest('PUT', '/api/user/audio-preferences/', $user, [
            'payload' => $payload,
            'version' => $version,
        ]);
    }
}
