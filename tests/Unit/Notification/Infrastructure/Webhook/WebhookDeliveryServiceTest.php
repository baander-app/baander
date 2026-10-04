<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookDeliveryServiceTest extends TestCase
{
    private WebhookDeliveryService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private HttpClientInterface&Stub $httpClient;
    private HmacSigner $hmacSigner;
    private LoggerInterface&Stub $logger;
    /** @var EntityRepository<WebhookEntity>&Stub */
    private EntityRepository&Stub $webhookRepo;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->hmacSigner = new HmacSigner();
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->webhookRepo = $this->createStub(EntityRepository::class);

        $this->entityManager->expects($this->atMost(1))->method('getRepository')
            ->with(WebhookEntity::class)
            ->willReturn($this->webhookRepo);

        $this->service = $this->createWebhookDeliveryServiceFixture();
    }

    private function createWebhookDeliveryServiceFixture(): WebhookDeliveryService
    {
        $fixture = new WebhookDeliveryService(
            $this->entityManager,
            $this->httpClient,
            $this->hmacSigner,
            $this->logger,
            new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => $host === 'baander.app' ? ['93.184.216.34'] : []),
        );
        return $fixture;
    }

    public function testIsUrlSafeBlocksPrivateIp(): void
    {
        // The isUrlSafe method resolves DNS, so we test with IP-based URLs.
        // These private IPs should be blocked.
        $this->assertFalse($this->service->isUrlSafe('http://127.0.0.1/webhook'));
        $this->assertFalse($this->service->isUrlSafe('http://10.0.0.1/webhook'));
        $this->assertFalse($this->service->isUrlSafe('http://192.168.1.1/webhook'));
        $this->assertFalse($this->service->isUrlSafe('http://172.16.0.1/webhook'));
        $this->assertFalse($this->service->isUrlSafe('http://169.254.0.1/webhook'));
    }

    public function testIsUrlSafeBlocksInvalidScheme(): void
    {
        $this->assertFalse($this->service->isUrlSafe('ftp://baander.app/webhook'));
        $this->assertFalse($this->service->isUrlSafe('gopher://baander.app/webhook'));
        $this->assertFalse($this->service->isUrlSafe('file:///etc/passwd'));
    }

    public function testIsUrlSafeBlocksInvalidUrl(): void
    {
        $this->assertFalse($this->service->isUrlSafe('not-a-url'));
        $this->assertFalse($this->service->isUrlSafe(''));
    }

    public function testIsUrlSafeAllowsPublicDnsUrl(): void
    {
        // DNS input is deterministic; the real destination policy still validates it.
        $this->assertTrue($this->service->isUrlSafe('https://baander.app/webhook'));
    }

    public function testIsUrlSafeBlocksUrlWithoutHost(): void
    {
        $this->assertFalse($this->service->isUrlSafe('https:///webhook'));
    }

    public function testDeliverAllSkipsWebhooksWithCategoryFilterMismatch(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(['security']);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        // Should not attempt any HTTP calls because category doesn't match
        $this->httpClient->expects($this->never())->method('request');

        $this->service->deliverAll(
            title: 'Test',
            body: 'Body',
            category: NotificationCategory::MediaChanges,
            notificationId: 'abc123',
            userId: Uuid::generate(),
        );
    }

    public function testDeliverAllSkipsWebhooksWithUnsafeUrl(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('http://127.0.0.1/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $this->httpClient->expects($this->never())->method('request');
        $this->logger->expects($this->once())->method('warning');
        $this->expectException(\RuntimeException::class);

        $this->service->deliverAll(
            title: 'Test',
            body: 'Body',
            category: NotificationCategory::Security,
            notificationId: 'abc123',
            userId: Uuid::generate(),
        );
    }

    public function testDeliverAllDeliversToMatchingWebhook(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())->method('request')
            ->willReturn($response);

        $this->entityManager->expects($this->once())->method('persist');

        $this->service->deliverAll(
            title: 'Test Title',
            body: 'Test Body',
            category: NotificationCategory::Security,
            notificationId: 'notif-123',
            userId: Uuid::generate(),
        );
    }

    public function testDeliverAllRespectsNullCategoryFilter(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        // null category filter means "all categories"
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(null); // all categories
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())->method('request')
            ->willReturn($response);

        $this->service->deliverAll(
            title: 'Test',
            body: 'Body',
            category: NotificationCategory::BackgroundJobs,
            notificationId: 'abc123',
            userId: Uuid::generate(),
        );
    }

    public function testDeliverAllNoWebhooksReturnsEarly(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $this->webhookRepo->method('findAll')->willReturn([]);

        $this->httpClient->expects($this->never())->method('request');

        $this->service->deliverAll(
            title: 'Test',
            body: 'Body',
            category: NotificationCategory::Security,
            notificationId: 'abc123',
            userId: Uuid::generate(),
        );
    }

    public function testDeliverPinsResolvedIpToPreventDnsRebinding(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $capturedOptions = null;
        $this->httpClient->expects($this->once())->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($response, &$capturedOptions) {
                $capturedOptions = $options;

                return $response;
            });

        $this->service->deliverAll(
            title: 'T',
            body: 'B',
            category: NotificationCategory::Security,
            notificationId: 'n1',
            userId: Uuid::generate(),
        );

        // The resolved, validated IP must be pinned via the 'resolve' option so the
        // client cannot re-resolve the host to a private address (DNS rebinding).
        $this->assertIsArray($capturedOptions);
        $this->assertArrayHasKey('resolve', $capturedOptions);
        $this->assertArrayHasKey('baander.app', $capturedOptions['resolve']);
        $this->assertNotSame('', $capturedOptions['resolve']['baander.app']);
        $this->assertSame(10, $capturedOptions['timeout']);
        $this->assertSame(10, $capturedOptions['max_duration']);
    }

    public function testDeliverResignsSignaturePerAttempt(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = $this->createWebhookDeliveryServiceFixture();

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $timestamps = [];
        $this->httpClient->expects($this->exactly(2))->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$timestamps) {
                $timestamps[] = $options['headers']['X-Webhook-Timestamp'];
                $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
                // First attempt 5xx (retry), second attempt 2xx (success).
                $response->method('getStatusCode')->willReturn(count($timestamps) === 1 ? 500 : 200);

                return $response;
            });

        $this->service->deliverAll(
            title: 'T',
            body: 'B',
            category: NotificationCategory::Security,
            notificationId: 'n1',
            userId: Uuid::generate(),
        );

        $this->assertCount(2, $timestamps);
        // The backoff between attempts spans a second boundary, so the per-attempt
        // timestamps must differ (pre-fix they were computed once and reused).
        $this->assertNotSame($timestamps[0], $timestamps[1]);
    }

    public function testDeliverLogsLastKnownStatusCodeOnFailure(): void
    {
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://baander.app/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($response);

        $persisted = null;
        $this->entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });

        $failure = null;
        try {
            $this->service->deliverAll(
                title: 'T',
                body: 'B',
                category: NotificationCategory::Security,
                notificationId: 'n1',
                userId: Uuid::generate(),
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $failure);

        // Pre-fix the terminal failure log was written with status_code=null even
        // though every attempt returned 500; it must now record the last known code.
        $this->assertInstanceOf(\App\Notification\Infrastructure\Doctrine\Entity\WebhookDeliveryLogEntity::class, $persisted);
        $this->assertSame(500, $persisted->getHttpStatusCode());
    }
}
