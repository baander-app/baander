<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Fixtures\AudioPreferencePayload;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class PreferenceRequestBodyTest extends TestCase
{
    #[DataProvider('invalidBodies')]
    public function testInvalidEnvelopePreservesPreferencesAndHistory(
        string $preference,
        string $method,
        string $suffix,
        string $body,
        int $status,
    ): void {
        $user = $this->createTestUser();
        $uri = '/api/user/' . $preference . '-preferences/';
        $payload = self::validPayload($preference);
        $saved = $this->assertJsonResponse($this->authenticatedRequest('PUT', $uri, $user, [
            'payload' => $payload,
            'version' => 0,
        ]), 200, 'data');
        $this->assertSame(1, $saved['data']['version']);
        $before = $this->readState($uri, $user);

        $response = $this->rawAuthenticatedRequest($method, $uri . $suffix, $user, $body);
        // Read persistence before checking the error so even a wrongly accepted body
        // is checked for unintended payload, version, and history changes.
        $this->assertSame($before, $this->readState($uri, $user));
        $error = $this->assertJsonResponse($response, $status, 'error');
        $this->assertSame($status, $error['error']['code']);
        $this->assertIsString($error['error']['message']);
        $this->assertNotSame('', $error['error']['message']);
    }

    /** @return iterable<string, array{string, string, string, string, int}> */
    public static function invalidBodies(): iterable
    {
        foreach (['audio', 'player', 'layout'] as $preference) {
            foreach (['save' => ['PUT', ''], 'rollback' => ['POST', 'rollback']] as $operation => [$method, $suffix]) {
                foreach (['empty' => '', 'whitespace' => '   ', 'malformed' => '{', 'trailing-comma' => '{"version":1,}'] as $case => $body) {
                    yield "$preference $operation $case" => [$preference, $method, $suffix, $body, 400];
                }
                foreach (['null' => 'null', 'string' => '"body"', 'integer' => '1', 'float' => '1.5', 'boolean' => 'true', 'empty-list' => '[]', 'list' => '[{"version":1}]'] as $case => $body) {
                    yield "$preference $operation root-$case" => [$preference, $method, $suffix, $body, 422];
                }

                $envelope = $operation === 'save' ? ['payload' => self::validPayload($preference)] : [];
                yield "$preference $operation version-missing" => [$preference, $method, $suffix, json_encode((object) $envelope, JSON_THROW_ON_ERROR), 422];
                foreach (['null' => null, 'string' => '1', 'float' => 1.0, 'true' => true, 'false' => false, 'empty-list' => [], 'list' => [1], 'object' => (object) ['value' => 1], 'negative' => -1] as $case => $version) {
                    yield "$preference $operation version-$case" => [$preference, $method, $suffix, json_encode([...$envelope, 'version' => $version], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 422];
                }
                if ($operation === 'rollback') {
                    yield "$preference rollback version-zero" => [$preference, $method, $suffix, '{"version":0}', 422];
                    continue;
                }

                yield "$preference save payload-missing" => [$preference, $method, $suffix, '{"version":1}', 422];
                foreach (['null' => null, 'string' => 'payload', 'integer' => 1, 'float' => 1.5, 'true' => true, 'false' => false, 'empty-list' => [], 'list' => ['value']] as $case => $invalidPayload) {
                    yield "$preference save payload-$case" => [$preference, $method, $suffix, json_encode(['payload' => $invalidPayload, 'version' => 1], JSON_THROW_ON_ERROR), 422];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function validPayload(string $preference): array
    {
        return match ($preference) {
            'audio' => AudioPreferencePayload::valid(['masterGain' => 1.5]),
            'layout' => ['mode' => 'expanded', 'activeTab' => 'queue'],
            'player' => [
                'shuffle' => false,
                'repeat' => 'off',
                'volume' => 0.8,
                'muted' => false,
                'crossfadeEnabled' => false,
                'crossfadeDuration' => 5.5,
                'replayGainEnabled' => false,
                'replayGainMode' => 'track',
                'replayGainPreAmp' => 0.5,
            ],
        };
    }

    private function rawAuthenticatedRequest(string $method, string $uri, User $user, string $body): Response
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_Test_User_Id' => $user->getId()->toString(),
        ], $body);

        return $this->client->getResponse();
    }

    /** @return array{payload: array<array-key, mixed>, version: int, history: array<array-key, mixed>} */
    private function readState(string $uri, User $user): array
    {
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', $uri, $user), 200, 'data');
        $history = $this->assertJsonResponse($this->authenticatedRequest('GET', $uri . 'history', $user), 200, 'data');

        return [
            'payload' => $saved['data']['payload'],
            'version' => $saved['data']['version'],
            'history' => $history['data']['history'],
        ];
    }
}
