<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for outgoing webhook management (Notification bounded context).
 *
 * Covers WebhookController — the entire controller is ROLE_ADMIN-gated.
 *
 *   GET    /api/webhooks/         list
 *   POST   /api/webhooks/         create (201, returns secret once)
 *   PUT    /api/webhooks/{id}     update
 *   DELETE /api/webhooks/{id}     delete (204)
 */
final class WebhookControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Class-level ROLE_ADMIN guard
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/webhooks/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexRequiresAdminRole(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/webhooks/', $user);

        $this->assertJsonResponse($response, 403);
    }

    // ---------------------------------------------------------------
    // GET / (index)
    // ---------------------------------------------------------------

    public function testIndexReturnsEmptyForNewDatabase(): void
    {
        $admin = $this->createAdminUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/webhooks/', $admin),
            200,
            'data',
        );

        $this->assertSame([], $data['data']);
    }

    public function testIndexReturnsCreatedWebhooks(): void
    {
        $admin = $this->createAdminUser();
        $this->createWebhook($admin, 'https://example.com/hook1');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/webhooks/', $admin),
            200,
            'data',
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame('https://example.com/hook1', $data['data'][0]['url']);
    }

    // ---------------------------------------------------------------
    // POST / (create)
    // ---------------------------------------------------------------

    public function testCreateRequiresAdminRole(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/webhooks/', $user, [
            'url' => 'https://example.com/hook',
        ]);

        $this->assertJsonResponse($response, 403);
    }

    public function testCreateReturns201AndSecret(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->createWebhook($admin, 'https://example.com/hook');
        $this->assertSame(201, $response->getStatusCode());

        // created() returns flat data (no 'data' wrapper)
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://example.com/hook', $data['url']);
        $this->assertArrayHasKey('secret', $data);
        $this->assertNotEmpty($data['secret']);
    }

    public function testCreateWithBlankUrlFailsValidation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => '']);

        $this->assertJsonResponse($response, 422);
    }

    public function testCreateWithInvalidUrlFailsValidation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => 'not-a-url']);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // PUT /{id} (update)
    // ---------------------------------------------------------------

    public function testUpdateChangesUrl(): void
    {
        $admin = $this->createAdminUser();
        $created = json_decode($this->createWebhook($admin, 'https://old.example.com')->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', '/api/webhooks/' . $created['id'], $admin, [
                'url' => 'https://new.example.com',
            ]),
            200,
            'data',
        );

        $this->assertSame('https://new.example.com', $data['data']['url']);
    }

    public function testUpdateReturns404ForUnknownId(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('PUT', '/api/webhooks/00000000-0000-0000-0000-000000000000', $admin, [
            'url' => 'https://example.com',
        ]);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // DELETE /{id}
    // ---------------------------------------------------------------

    public function testDeleteRemovesWebhook(): void
    {
        $admin = $this->createAdminUser();
        $created = json_decode($this->createWebhook($admin, 'https://example.com')->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $response = $this->authenticatedRequest('DELETE', '/api/webhooks/' . $created['id'], $admin);

        $this->assertSame(204, $response->getStatusCode());

        // Gone.
        $listData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/webhooks/', $admin),
            200,
            'data',
        );
        $this->assertSame([], $listData['data']);
    }

    public function testDeleteReturns404ForUnknownId(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('DELETE', '/api/webhooks/00000000-0000-0000-0000-000000000000', $admin);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createWebhook(User $admin, string $url)
    {
        return $this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => $url]);
    }
}
