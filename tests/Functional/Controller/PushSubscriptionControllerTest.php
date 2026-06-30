<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

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

        $data = $this->assertJsonResponse($second, 200, 'data');
        $this->assertSame('already_subscribed', $data['data']['status']);
    }

    public function testSubscribeWithHttpEndpointFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('http://fcm.googleapis.com/fcm/send/abc');
        $payload['endpoint'] = 'http://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(6));

        $response = $this->authenticatedRequest('POST', '/api/push/subscribe', $user, $payload);

        $data = $this->assertJsonResponse($response, 422);
        $this->assertStringContainsString('HTTPS', $data['error']['message']);
    }

    public function testSubscribeWithDisallowedDomainFails(): void
    {
        $user = $this->createTestUser();
        $payload = $this->validPayload('https://example.com/push/abc');

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

    private function fcmEndpoint(): string
    {
        return 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(8));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BNc-' . bin2hex(random_bytes(16)),
                'auth' => bin2hex(random_bytes(8)),
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
