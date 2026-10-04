<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Functional tests for layout-preferences management.
 *
 * Covers LayoutPreferencesController:
 *   GET    /api/user/layout-preferences/          get (404 when absent)
 *   PUT    /api/user/layout-preferences/          save (versioned)
 *   GET    /api/user/layout-preferences/history   version history
 *   POST   /api/user/layout-preferences/rollback  restore a previous version
 *
 * Saves require the current persisted version and reject stale writes without adding history.
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
        $payload = $this->validPayload('expanded', 'details');

        $this->savePreferences($user, $payload, 0);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user),
            200,
            'data',
        );

        $this->assertEquals($payload, $data['data']['payload']);
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
        $payload = $this->validPayload('compact', 'queue');

        $data = $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200, 'data');

        $this->assertEquals($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    public function testSaveIncrementsVersion(): void
    {
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), 0), 200, 'data');
        $this->assertSame(1, $first['data']['version']);

        $second = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('compact', 'queue'), 1), 200, 'data');
        $this->assertSame(2, $second['data']['version']);
    }

    public function testSaveRejectsStaleVersionWithoutChangingPayloadOrHistory(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, $this->validPayload('expanded', 'queue'), 0);   // → version 1
        $stale = $this->savePreferences($user, $this->validPayload('compact', 'queue'), 0); // stale version 0

        $conflict = $this->assertJsonResponse($stale, 409);
        $this->assertSame(1, $conflict['error']['details']['currentVersion']);
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user), 200, 'data');
        $this->assertSame($this->validPayload('expanded', 'queue'), $saved['data']['payload']);
        $this->assertSame(1, $saved['data']['version']);
        $history = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/layout-preferences/history', $user), 200, 'data');
        $this->assertCount(1, $history['data']['history']);
        $retry = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), 1), 200, 'data');
        $this->assertSame(2, $retry['data']['version']);
    }

    public function testSaveRejectsNonzeroVersionWhenAbsent(): void
    {
        $user = $this->createTestUser();
        $conflict = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), 3), 409);
        $this->assertSame(0, $conflict['error']['details']['currentVersion']);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user), 404);
    }

    public function testSaveRejectsStalePositiveAndFutureVersions(): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), 0), 200);
        $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), 1), 200);
        foreach ([1, 9] as $expectedVersion) {
            $conflict = $this->assertJsonResponse($this->savePreferences($user, $this->validPayload('expanded', 'queue'), $expectedVersion), 409);
            $this->assertSame(2, $conflict['error']['details']['currentVersion']);
        }
    }

    public function testSaveWithNegativeVersionFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->savePreferences($user, $this->validPayload('expanded', 'queue'), -1);

        $this->assertJsonResponse($response, 422);
    }

    /** @return iterable<string, array{string, string}> */
    public static function supportedLayouts(): iterable
    {
        foreach (['compact', 'expanded'] as $mode) {
            foreach (['queue', 'lyrics', 'details', 'info'] as $activeTab) {
                yield $mode . ' ' . $activeTab => [$mode, $activeTab];
            }
        }
    }

    #[DataProvider('supportedLayouts')]
    public function testSaveAcceptsEverySupportedLayout(string $mode, string $activeTab): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload($mode, $activeTab);

        $data = $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200, 'data');

        $this->assertSame($payload, $data['data']['payload']);
        $this->assertSame(1, $data['data']['version']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidLayouts(): iterable
    {
        yield 'obsolete pioneer mode' => [['mode' => 'pioneer', 'activeTab' => 'queue']];
        yield 'unknown mode' => [['mode' => 'grid', 'activeTab' => 'queue']];
        yield 'obsolete library tab' => [['mode' => 'expanded', 'activeTab' => 'library']];
        yield 'unknown tab' => [['mode' => 'expanded', 'activeTab' => 'unknown']];
        yield 'blank tab' => [['mode' => 'expanded', 'activeTab' => '']];
        yield 'missing mode' => [['activeTab' => 'queue']];
        yield 'missing tab' => [['mode' => 'expanded']];
        yield 'numeric mode' => [['mode' => 1, 'activeTab' => 'queue']];
        yield 'numeric tab' => [['mode' => 'expanded', 'activeTab' => 1]];
        yield 'extra field' => [['mode' => 'expanded', 'activeTab' => 'queue', 'extra' => true]];
    }

    /** @param array<string, mixed> $invalidPayload */
    #[DataProvider('invalidLayouts')]
    public function testSaveRejectsInvalidLayoutWithoutChangingPayloadVersionOrHistory(array $invalidPayload): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload();
        $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200);
        $historyBefore = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/history', $user),
            200,
            'data',
        );

        $this->assertJsonResponse($this->savePreferences($user, $invalidPayload, 1), 422);

        $saved = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/', $user),
            200,
            'data',
        );
        $this->assertSame($payload, $saved['data']['payload']);
        $this->assertSame(1, $saved['data']['version']);
        $historyAfter = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/layout-preferences/history', $user),
            200,
            'data',
        );
        $this->assertSame($historyBefore['data']['history'], $historyAfter['data']['history']);
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

        $this->savePreferences($user, $this->validPayload('expanded', 'queue'), 0);
        $this->savePreferences($user, $this->validPayload('compact', 'queue'), 1);

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
        $firstPayload = $this->validPayload('expanded', 'queue');
        $secondPayload = $this->validPayload('compact', 'queue');

        $this->savePreferences($user, $firstPayload, 0);   // version 1
        $this->savePreferences($user, $secondPayload, 1);  // version 2

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/user/layout-preferences/rollback', $user, ['version' => 1]),
            200,
            'data',
        );

        $this->assertEquals($firstPayload, $data['data']['payload'], 'Rollback must restore the version-1 payload.');
        $this->assertSame(3, $data['data']['version']);
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

    /** @return array{mode: string, activeTab: string} */
    private function validPayload(string $mode = 'expanded', string $activeTab = 'queue'): array
    {
        return ['mode' => $mode, 'activeTab' => $activeTab];
    }

    /** @param array<string, mixed> $payload */
    private function savePreferences(User $user, array $payload, int $version): Response
    {
        return $this->authenticatedRequest('PUT', '/api/user/layout-preferences/', $user, [
            'payload' => $payload,
            'version' => $version,
        ]);
    }
}
