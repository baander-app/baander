<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookDeliveryServiceTest extends TestCase
{
    private WebhookDeliveryService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private HttpClientInterface&MockObject $httpClient;
    private HmacSigner $hmacSigner;
    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $webhookRepo;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->hmacSigner = new HmacSigner();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->webhookRepo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(WebhookEntity::class)
            ->willReturn($this->webhookRepo);

        $this->service = new WebhookDeliveryService(
            $this->entityManager,
            $this->httpClient,
            $this->hmacSigner,
            $this->logger,
            new JsonEncoder(),
        );
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
        $this->assertFalse($this->service->isUrlSafe('ftp://example.com/webhook'));
        $this->assertFalse($this->service->isUrlSafe('gopher://example.com/webhook'));
        $this->assertFalse($this->service->isUrlSafe('file:///etc/passwd'));
    }

    public function testIsUrlSafeBlocksInvalidUrl(): void
    {
        $this->assertFalse($this->service->isUrlSafe('not-a-url'));
        $this->assertFalse($this->service->isUrlSafe(''));
    }

    public function testIsUrlSafeAllowsPublicDnsUrl(): void
    {
        // A real public domain should resolve and not be in the blocklist.
        // We use a well-known domain that should resolve to a public IP.
        $this->assertTrue($this->service->isUrlSafe('https://example.com/webhook'));
    }

    public function testIsUrlSafeBlocksUrlWithoutHost(): void
    {
        $this->assertFalse($this->service->isUrlSafe('https:///webhook'));
    }

    public function testDeliverAllSkipsWebhooksWithCategoryFilterMismatch(): void
    {
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://example.com/webhook');
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
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('http://127.0.0.1/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $this->httpClient->expects($this->never())->method('request');
        $this->logger->expects($this->once())->method('warning');

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
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://example.com/webhook');
        $webhook->setCategoryFilter(null);
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
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
        // null category filter means "all categories"
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://example.com/webhook');
        $webhook->setCategoryFilter(null); // all categories
        $webhook->setSecretHash('hashed');

        $this->webhookRepo->method('findAll')->willReturn([$webhook]);

        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
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
}
