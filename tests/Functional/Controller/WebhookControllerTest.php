<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Application\Port\WebhookDestinationPortInterface;
use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

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
    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->set(WebhookDestinationPortInterface::class, new WebhookDestinationPolicy(
            dnsResolver: static fn (string $host): array => match ($host) {
                'baander.app', 'old.baander.app', 'new.baander.app' => ['93.184.216.34'],
                'mixed.baander.app' => ['93.184.216.34', '192.168.1.2'],
                default => [],
            },
        ));
    }

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
        $this->createWebhook($admin, 'https://baander.app/hook1');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/webhooks/', $admin),
            200,
            'data',
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame('https://baander.app/hook1', $data['data'][0]['url']);
    }

    // ---------------------------------------------------------------
    // POST / (create)
    // ---------------------------------------------------------------

    public function testCreateRequiresAdminRole(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/webhooks/', $user, [
            'url' => 'https://baander.app/hook',
        ]);

        $this->assertJsonResponse($response, 403);
    }

    public function testCreateReturns201AndSecret(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->createWebhook($admin, 'https://baander.app/hook');
        $this->assertSame(201, $response->getStatusCode());

        // created() returns flat data (no 'data' wrapper)
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://baander.app/hook', $data['url']);
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
        $created = json_decode($this->createWebhook($admin, 'https://old.baander.app')->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', '/api/webhooks/' . $created['id'], $admin, [
                'url' => 'https://new.baander.app',
            ]),
            200,
            'data',
        );

        $this->assertSame('https://new.baander.app', $data['data']['url']);
    }

    public function testUpdateReturns404ForUnknownId(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('PUT', '/api/webhooks/00000000-0000-0000-0000-000000000000', $admin, [
            'url' => 'https://baander.app',
        ]);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // DELETE /{id}
    // ---------------------------------------------------------------

    public function testDeleteRemovesWebhook(): void
    {
        $admin = $this->createAdminUser();
        $created = json_decode($this->createWebhook($admin, 'https://baander.app')->getContent(), true, 512, JSON_THROW_ON_ERROR);

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

    public function testMixedPublicAndPrivateDnsAnswersAreRejectedWithoutChangingStoredConfiguration(): void
    {
        $admin = $this->createAdminUser();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, [
            'url' => 'https://mixed.baander.app/hook',
        ]), 422);
        $this->assertSame([], $this->entityManager->getRepository(WebhookEntity::class)->findAll());

        $created = $this->assertJsonResponse($this->createWebhook($admin, 'https://baander.app/hook'), 201);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/webhooks/' . $created['id'], $admin, [
            'url' => 'https://mixed.baander.app/hook',
            'category_filter' => ['security'],
        ]), 422);

        $stored = $this->storedWebhook($created['id']);
        $this->assertSame('https://baander.app/hook', $stored->getUrl());
        $this->assertNull($stored->getCategoryFilter());
    }

    public function testInvalidCategoryFiltersAreRejectedWithoutChangingStoredConfiguration(): void
    {
        $admin = $this->createAdminUser();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, [
            'url' => 'https://baander.app/hook',
            'category_filter' => ['unknown'],
        ]), 422);
        $this->assertSame([], $this->entityManager->getRepository(WebhookEntity::class)->findAll());

        $created = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, [
            'url' => 'https://baander.app/hook',
            'category_filter' => ['security'],
        ]), 201);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/webhooks/' . $created['id'], $admin, [
            'url' => 'https://new.baander.app/hook',
            'category_filter' => ['key' => 'security'],
        ]), 422);

        $stored = $this->storedWebhook($created['id']);
        $this->assertSame('https://baander.app/hook', $stored->getUrl());
        $this->assertSame(['security'], $stored->getCategoryFilter());
    }

    public function testSecretRotationRequiresAuthentication(): void
    {
        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/webhooks/' . Uuid::generate()->toString() . '/rotate-secret'), 401);
    }

    public function testSecretRotationRequiresAdminRole(): void
    {
        $admin = $this->createAdminUser();
        $created = $this->assertJsonResponse($this->createWebhook($admin, 'https://baander.app/hook'), 201);
        $originalCiphertext = $this->storedWebhook($created['id'])->getEncryptedSecret();
        $user = $this->createTestUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/' . $created['id'] . '/rotate-secret', $user), 403);

        $this->assertSame($originalCiphertext, $this->storedWebhook($created['id'])->getEncryptedSecret());
    }

    public function testAdminRotatesTheStoredEncryptedSecretAndListDoesNotExposeIt(): void
    {
        $admin = $this->createAdminUser();
        $created = $this->assertJsonResponse($this->createWebhook($admin, 'https://baander.app/hook'), 201);
        $originalCiphertext = $this->storedWebhook($created['id'])->getEncryptedSecret();
        $rotated = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/' . $created['id'] . '/rotate-secret', $admin), 200, 'data')['data'];

        $this->assertSame($created['id'], $rotated['id']);
        $this->assertSame(2, $rotated['signing_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rotated['secret']);
        $this->assertNotSame($created['secret'], $rotated['secret']);
        $stored = $this->storedWebhook($created['id']);
        $this->assertSame(2, $stored->getSigningVersion());
        $ciphertext = $stored->getEncryptedSecret();
        $this->assertNotSame($originalCiphertext, $ciphertext);
        $this->assertNotSame($rotated['secret'], $ciphertext);
        $secrets = static::getContainer()->get(WebhookSecretPortInterface::class);
        $this->assertSame($rotated['secret'], $secrets->decrypt($ciphertext));

        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/webhooks/', $admin), 200, 'data')['data'];
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('secret', $listed[0]);
        $this->assertArrayNotHasKey('secret_hash', $listed[0]);
        $this->assertArrayNotHasKey('encrypted_secret', $listed[0]);
    }

    public function testFreshSchemaAndGeneratedMappingRequireOnlyEncryptedSecrets(): void
    {
        $metadata = $this->entityManager->getClassMetadata(WebhookEntity::class);
        $sql = (new \Doctrine\ORM\Tools\SchemaTool($this->entityManager))->getCreateSchemaSql([$metadata]);
        $ddl = implode("\n", $sql);
        $this->assertStringContainsString('encrypted_secret TEXT NOT NULL', $ddl);
        $this->assertStringNotContainsString('secret_hash', $ddl);
        $this->assertStringNotContainsString('signing_version', $ddl);

        $columns = $this->entityManager->getConnection()->createSchemaManager()->listTableColumns('webhooks');
        $this->assertTrue($columns['encrypted_secret']->getNotnull());
        $this->assertArrayNotHasKey('secret_hash', $columns);
        $this->assertArrayNotHasKey('signing_version', $columns);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createWebhook(User $admin, string $url): Response
    {
        $response = $this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => $url]);
        $this->assertJsonResponse($response, 201);

        return $response;
    }

    private function storedWebhook(string $id): WebhookEntity
    {
        $webhook = $this->entityManager->getRepository(WebhookEntity::class)->find(Uuid::fromString($id));
        $this->assertInstanceOf(WebhookEntity::class, $webhook);
        $this->entityManager->refresh($webhook);

        return $webhook;
    }
}
