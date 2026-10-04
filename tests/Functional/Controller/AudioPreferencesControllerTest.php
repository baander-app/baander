<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Fixtures\AudioPreferencePayload;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $payload = AudioPreferencePayload::valid(['masterGain' => 1.5]);

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
            'payload' => AudioPreferencePayload::valid(),
            'version' => 0,
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testSaveCreatesPreferencesWithVersionOne(): void
    {
        $user = $this->createTestUser();
        $payload = AudioPreferencePayload::valid(['enabled' => true]);

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

        $first = $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 0), 200, 'data');
        $this->assertSame(1, $first['data']['version']);

        $second = $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(['preset' => 'BASS']), 1), 200, 'data');
        $this->assertSame(2, $second['data']['version']);
        $this->assertEquals(AudioPreferencePayload::valid(['preset' => 'BASS']), $second['data']['payload']);
    }

    public function testSaveRejectsStaleVersionWithoutChangingPayloadOrHistory(): void
    {
        $user = $this->createTestUser();

        $this->savePreferences($user, AudioPreferencePayload::valid(), 0);   // -> version 1
        $stale = $this->savePreferences($user, AudioPreferencePayload::valid(['preset' => 'BASS']), 0); // stale version 0

        $conflict = $this->assertJsonResponse($stale, 409);
        $this->assertSame(1, $conflict['error']['details']['currentVersion']);
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user), 200, 'data');
        $this->assertEquals(AudioPreferencePayload::valid(), $saved['data']['payload']);
        $this->assertSame(1, $saved['data']['version']);
        $history = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/history', $user), 200, 'data');
        $this->assertCount(1, $history['data']['history']);
        $retry = $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 1), 200, 'data');
        $this->assertSame(2, $retry['data']['version']);
    }

    public function testSaveRejectsNonzeroVersionWhenAbsent(): void
    {
        $user = $this->createTestUser();
        $conflict = $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 3), 409);
        $this->assertSame(0, $conflict['error']['details']['currentVersion']);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user), 404);
    }

    public function testSaveRejectsStalePositiveAndFutureVersions(): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 0), 200);
        $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 1), 200);
        foreach ([1, 9] as $expectedVersion) {
            $conflict = $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), $expectedVersion), 409);
            $this->assertSame(2, $conflict['error']['details']['currentVersion']);
        }
    }

    public function testSaveWithNegativeVersionFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->savePreferences($user, AudioPreferencePayload::valid(), -1);

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

        $this->savePreferences($user, AudioPreferencePayload::valid(), 0);
        $this->savePreferences($user, AudioPreferencePayload::valid(['preset' => 'BASS']), 1);

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
        $firstPayload = AudioPreferencePayload::valid();
        $secondPayload = AudioPreferencePayload::valid(['preset' => 'BASS']);

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

    /** @param array<string, mixed> $payload */
    #[DataProvider('validPayloads')]
    public function testSaveAcceptsCompleteBoundaryAndEnumSnapshots(array $payload): void
    {
        $user = $this->createTestUser();
        $saved = $this->assertJsonResponse($this->savePreferences($user, $payload, 0), 200, 'data');
        $this->assertEquals($payload, $saved['data']['payload']);
        $state = $this->readAudioState($user);
        $this->assertEquals($payload, $state['saved']['payload']);
        $this->assertCount(1, $state['history']['history']);
        $this->assertEquals($payload, $state['history']['history'][0]['payload']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validPayloads(): iterable
    {
        foreach (['lower' => ['gain' => -12, 'q' => 0.1, 'compressorThreshold' => -50, 'compressorRatio' => 1, 'compressorKnee' => 0, 'compressorAttack' => 0.1, 'compressorRelease' => 10, 'masterGain' => -12, 'stereoWidth' => 0], 'upper' => ['gain' => 12, 'q' => 10, 'compressorThreshold' => 0, 'compressorRatio' => 20, 'compressorKnee' => 40, 'compressorAttack' => 100, 'compressorRelease' => 1000, 'masterGain' => 12, 'stereoWidth' => 2]] as $case => $values) {
            $band = ['gain' => $values['gain'], 'q' => $values['q']];
            unset($values['gain'], $values['q']);
            yield $case => [AudioPreferencePayload::valid([...$values, 'bands' => array_fill(0, 10, $band), 'chainOrder' => array_reverse(AudioPreferencePayload::valid()['chainOrder'])])];
        }
        foreach (['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS'] as $index => $preset) {
            yield $preset => [AudioPreferencePayload::valid([
                'preset' => $preset,
                'visualizerMode' => ['enhanced-spectrum', 'circular', 'spectrogram', 'particles', 'spectrum', 'meters', 'phase'][$index % 7],
                'targetLufs' => [-14, -16, -18, -23][$index % 4],
                'stereoMode' => ['normal', 'mid', 'side'][$index % 3],
                'crossfeedPreset' => ['light', 'normal', 'heavy'][$index % 3],
                'bands' => array_fill(0, 10, ['gain' => 1.5, 'q' => 0.7]),
                'compressorThreshold' => -20.5,
                'compressorRatio' => 2.5,
                'compressorKnee' => 10.5,
                'compressorAttack' => 2.5,
                'compressorRelease' => 100.5,
                'masterGain' => 0.5,
                'stereoWidth' => 1.5,
            ])];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadPreservesSavedPreferencesAndHistory(array $payload): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->savePreferences($user, AudioPreferencePayload::valid(), 0), 200);
        $before = $this->readAudioState($user);
        $response = $this->savePreferences($user, $payload, 1);
        $this->assertSame($before, $this->readAudioState($user));
        $this->assertJsonResponse($response, 422, 'error');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $valid = AudioPreferencePayload::valid();
        foreach ($valid as $field => $value) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing-' . $field => [$missing];
            yield 'null-' . $field => [array_replace($valid, [$field => null])];
            if (is_bool($value)) {
                foreach ([0, 1, 'true', 'false'] as $index => $invalid) {
                    yield "boolean-$field-$index" => [array_replace($valid, [$field => $invalid])];
                }
            }
        }
        foreach (['schemaVersion' => 2, 'bandsV2' => $valid['bands'], 'eqMode' => 'simple', 'compressor' => [], 'peqPoints' => [], 'compareSlots' => [], 'activeProfileId' => null, 'unknown' => true] as $field => $value) {
            yield 'extra-' . $field => [array_replace($valid, [$field => $value])];
        }
        foreach (['compressorThreshold' => [-50, 0], 'compressorRatio' => [1, 20], 'compressorKnee' => [0, 40], 'compressorAttack' => [0.1, 100], 'compressorRelease' => [10, 1000], 'masterGain' => [-12, 12], 'stereoWidth' => [0, 2]] as $field => [$min, $max]) {
            foreach (['below' => $min - 0.01, 'above' => $max + 0.01, 'string' => (string) $min, 'boolean' => false, 'list' => []] as $case => $invalid) {
                yield "$field-$case" => [array_replace($valid, [$field => $invalid])];
            }
        }
        foreach (['preset' => 'CUSTOM', 'visualizerMode' => 'waveform', 'stereoMode' => 'mono', 'crossfeedPreset' => 'custom', 'targetLufs' => -15] as $field => $value) {
            yield 'enum-' . $field => [array_replace($valid, [$field => $value])];
        }
        yield 'lufs-string' => [array_replace($valid, ['targetLufs' => '-14'])];
        foreach (['nine' => array_slice($valid['bands'], 0, 9), 'eleven' => [...$valid['bands'], $valid['bands'][0]], 'legacy-gains' => array_fill(0, 10, 0), 'object' => ['band' => $valid['bands'][0]], 'numeric-object' => (object) $valid['bands']] as $case => $bands) {
            yield 'bands-' . $case => [array_replace($valid, ['bands' => $bands])];
        }
        foreach (['missing-gain' => ['q' => 1], 'missing-q' => ['gain' => 0], 'extra' => ['gain' => 0, 'q' => 1, 'frequency' => 100], 'gain-low' => ['gain' => -12.01, 'q' => 1], 'gain-high' => ['gain' => 12.01, 'q' => 1], 'q-low' => ['gain' => 0, 'q' => 0.09], 'q-high' => ['gain' => 0, 'q' => 10.01], 'gain-string' => ['gain' => '0', 'q' => 1], 'q-string' => ['gain' => 0, 'q' => '1'], 'gain-bool' => ['gain' => false, 'q' => 1], 'q-bool' => ['gain' => 0, 'q' => true], 'null' => null, 'string' => 'band', 'list' => [0, 1], 'empty-object' => (object) [], 'empty-list' => [], 'gain-null' => ['gain' => null, 'q' => 1], 'q-null' => ['gain' => 0, 'q' => null]] as $case => $band) {
            $bands = $valid['bands'];
            $bands[0] = $band;
            yield 'band-' . $case => [array_replace($valid, ['bands' => $bands])];
        }
        foreach (['missing' => array_slice($valid['chainOrder'], 0, 5), 'duplicate' => ['eq', 'eq', 'stereo', 'crossfeed', 'loudness', 'masterGain'], 'unknown' => ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'unknown'], 'extra' => [...$valid['chainOrder'], 'eq'], 'object' => array_combine($valid['chainOrder'], $valid['chainOrder']), 'numeric-object' => (object) $valid['chainOrder'], 'string' => 'eq'] as $case => $order) {
            yield 'chain-' . $case => [array_replace($valid, ['chainOrder' => $order])];
        }
    }

    /** @return array<string, mixed> */
    private function readAudioState(User $user): array
    {
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/', $user), 200, 'data');
        $history = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/audio-preferences/history', $user), 200, 'data');

        return ['saved' => $saved['data'], 'history' => $history['data']];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function savePreferences(User $user, array $payload, int $version): Response
    {
        return $this->authenticatedRequest('PUT', '/api/user/audio-preferences/', $user, [
            'payload' => $payload,
            'version' => $version,
        ]);
    }
}
