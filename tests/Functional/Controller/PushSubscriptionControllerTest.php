<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for browser push-subscription management.
 *
 * Covers PushSubscriptionController:
 *   POST   /api/push/subscribe        subscribe (validate + persist)
 *   DELETE /api/push/subscribe        unsubscribe by endpoint
 *   DELETE /api/push/subscriptions    remove all subscriptions for the user
 *
 * Validation rules under test:
 *   - endpoint must be a valid https URL
 *   - endpoint host must be a known push service (FCM/Mozilla/Apple/...)
 *   - keys.p256dh + keys.auth required
 *   - contentEncoding must be aesgcm | aes128gcm
 */
final class PushSubscriptionControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/push/subscribe
    // ---------------------------------------------------------------

    public function testSubscribeRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/push/subscribe', $this->validPayload($this->fcmEndpoint()));

        $this->assertJsonResponse($response, 401);
    }

    public function testSubscribeCreatesSubscription(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($this->fcmEndpoint()));

        $data = $this->assertJsonResponse($response, 201);
        $this->assertSame('subscribed', $data['status']);
    }

    public function testSubscribeReturnsAlreadySubscribedForKnownEndpoint(): void
    {
        $user = $this->createTestUser();
        $endpoint = $this->fcmEndpoint();

        $first = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpoint));
        $this->assertSame(201, $first->getStatusCode());

        // Same endpoint again -> recognised, not re-created.
        $second = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpoint));

        $data = $this->assertJsonResponse($second, 200);
        $this->assertSame(['status' => 'subscribed'], $data);
    }

    public function testSubscribeWithHttpEndpointFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('http://push.baander.app/abc');
        $payload['endpoint'] = 'http://push.baander.app/' . (random_bytes(6) |> bin2hex(...));

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $payload);

        $data = $this->assertJsonResponse($response, 422);
        $this->assertStringContainsString('HTTPS', $data['error']['message']);
    }

    public function testSubscribeWithDisallowedDomainFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('https://unknown.baander.app/push/abc');

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $payload);

        $data = $this->assertJsonResponse($response, 422);
        $this->assertStringContainsString('known push service', $data['error']['message']);
    }

    public function testSubscribeWithMissingKeysFails(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, [
            'endpoint' => $this->fcmEndpoint(),
            'contentEncoding' => 'aes128gcm',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testSubscribeWithInvalidContentEncodingFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload($this->fcmEndpoint());
        $payload['contentEncoding'] = 'invalid-encoding';

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $payload);

        $this->assertJsonResponse($response, 422);
    }

    public function testSubscribeWithBlankEndpointFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload($this->fcmEndpoint());
        $payload['endpoint'] = '';

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $payload);

        $this->assertJsonResponse($response, 422);
    }

    /** @return iterable<string, array{string, int}> */
    public static function malformedSubscribeBodies(): iterable
    {
        yield 'endpoint that is a list' => ['{"endpoint": [], "keys": {"p256dh": "key", "auth": "auth"}, "contentEncoding": "aes128gcm"}', 422];
        yield 'keys that are a string' => ['{"endpoint": "https://push.baander.app/a", "keys": "keys", "contentEncoding": "aes128gcm"}', 422];
        yield 'key that is an object' => ['{"endpoint": "https://push.baander.app/a", "keys": {"p256dh": {"x": 1}, "auth": "auth"}, "contentEncoding": "aes128gcm"}', 422];
        yield 'body that is a string' => ['"subscribe"', 400];
        yield 'malformed json' => ['{"endpoint":', 400];
        yield 'empty body' => ['', 400];
    }

    #[DataProvider('malformedSubscribeBodies')]
    public function testAMalformedSubscribeBodyAnswersAClientErrorAndStoresNothing(string $body, int $status): void
    {
        $user = $this->createTestUser();

        $this->assertJsonResponse($this->rawRequest('POST', '/api/push/subscribe', $user, $body), $status, 'error');
        $this->assertSame(0, $this->storedSubscriptions($user));
    }

    public function testAMalformedUnsubscribeBodyAnswers400(): void
    {
        $user = $this->createTestUser();

        $this->assertJsonResponse($this->rawRequest('DELETE', '/api/push/subscribe', $user, '{"endpoint":'), 400, 'error');
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function oversizedSubscriptions(): iterable
    {
        yield 'endpoint over 2048 characters' => [static fn (array $payload): array => [...$payload, 'endpoint' => 'https://push.baander.app/' . str_repeat('e', 2048)]];
        yield 'public key over 128 characters' => [static fn (array $payload): array => [...$payload, 'keys' => [...$payload['keys'], 'p256dh' => str_repeat('k', 129)]]];
        yield 'authentication secret over 64 characters' => [static fn (array $payload): array => [...$payload, 'keys' => [...$payload['keys'], 'auth' => str_repeat('a', 65)]]];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $oversize */
    #[DataProvider('oversizedSubscriptions')]
    public function testAKeyOrEndpointOverItsLengthAnswers422(callable $oversize): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $oversize($this->validPayload($this->fcmEndpoint())));

        $this->assertJsonResponse($response, 422);
        $this->assertSame(0, $this->storedSubscriptions($user));
    }

    public function testThe21stRegistrationEvictsTheLongestRegisteredSubscription(): void
    {
        $user = $this->createTestUser();
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO push_subscriptions (id, user_id, endpoint, public_key, auth_key, content_encoding, created_at)
                SELECT gen_random_uuid(), ?, 'https://push.baander.app/registered-' || i, 'key', 'auth', 'aes128gcm',
                       now() - make_interval(days => 21 - i)
                FROM generate_series(1, 20) AS i
                SQL,
            [$user->getId()->toString()],
        );
        $newest = $this->fcmEndpoint();

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($newest)), 201);

        $endpoints = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT endpoint FROM push_subscriptions WHERE user_id = ? ORDER BY created_at',
            [$user->getId()->toString()],
        );
        $this->assertCount(20, $endpoints);
        $this->assertNotContains('https://push.baander.app/registered-1', $endpoints);
        $this->assertSame('https://push.baander.app/registered-2', $endpoints[0]);
        $this->assertSame($newest, $endpoints[19]);
    }

    public function testTheUserAgentIsStoredAsAValidLabelOfAtMost255Characters(): void
    {
        $user = $this->createTestUser();
        $endpoint = $this->fcmEndpoint();
        $this->client->request('POST', '/api/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => "Browser\xff/" . str_repeat('ø', 600),
            BaanderHeader::TestUserId->serverKey() => $user->getId()->toString(),
        ], json_encode($this->validPayload($endpoint), JSON_THROW_ON_ERROR));

        $this->assertJsonResponse($this->client->getResponse(), 201);
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT user_agent FROM push_subscriptions WHERE endpoint = ?', [$endpoint]);
        $this->assertIsString($stored);
        $this->assertSame(255, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertStringStartsWith('Browser?/ø', $stored);
    }

    // ---------------------------------------------------------------
    // DELETE /api/push/subscribe
    // ---------------------------------------------------------------

    public function testUnsubscribeRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/push/subscribe', ['endpoint' => $this->fcmEndpoint()]);

        $this->assertJsonResponse($response, 401);
    }

    public function testUnsubscribeRemovesSubscription(): void
    {
        $user = $this->createTestUser();
        $endpoint = $this->fcmEndpoint();

        $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpoint));

        $unsubscribe = $this->authenticatedRequest('DELETE', '/api/push/subscribe', $user, ['endpoint' => $endpoint]);
        $this->assertSame(204, $unsubscribe->getStatusCode());

        // After removal the endpoint is unknown again, so re-subscribing creates it.
        $resubscribe = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpoint));
        $this->assertSame(201, $resubscribe->getStatusCode());
    }

    public function testUnsubscribeIsIdempotentForUnknownEndpoint(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/push/subscribe', $user, ['endpoint' => $this->fcmEndpoint()]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testUnsubscribeRequiresEndpoint(): void
    {
        $user = $this->createTestUser();

        // Valid JSON body that simply omits the endpoint key.
        $response = $this->authenticatedRequest('DELETE', '/api/push/subscribe', $user, ['status' => 'ok']);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // DELETE /api/push/subscriptions  (remove all)
    // ---------------------------------------------------------------

    public function testRemoveAllRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/push/subscriptions');

        $this->assertJsonResponse($response, 401);
    }

    public function testRemoveAllClearsUserSubscriptions(): void
    {
        $user = $this->createTestUser();
        $endpointA = $this->fcmEndpoint();
        $endpointB = $this->fcmEndpoint();

        $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpointA));
        $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpointB));

        $removeAll = $this->authenticatedRequest('DELETE', '/api/push/subscriptions', $user);
        $this->assertSame(204, $removeAll->getStatusCode());

        // Both endpoints are now unknown -> re-subscribing creates them again.
        foreach ([$endpointA, $endpointB] as $endpoint) {
            $resubscribe = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $this->validPayload($endpoint));
            $this->assertSame(201, $resubscribe->getStatusCode(), "Endpoint {$endpoint} should be creatable again after removeAll.");
        }
    }

    public function testRemoveAllReturnsNoContent(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/push/subscriptions', $user);

        $this->assertSame(204, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function rawRequest(string $method, string $uri, User $user, string $body): Response
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            BaanderHeader::TestUserId->serverKey() => $user->getId()->toString(),
        ], $body);

        return $this->client->getResponse();
    }

    private function storedSubscriptions(User $user): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM push_subscriptions WHERE user_id = ?',
            [$user->getId()->toString()],
        );
    }

    private function fcmEndpoint(): string
    {
        return 'https://push.baander.app/' . (random_bytes(8) |> bin2hex(...));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BNc-' . (random_bytes(16) |> bin2hex(...)),
                'auth' => random_bytes(8) |> bin2hex(...),
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
