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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebhookRedirectTest extends TestCase
{
    public function testDeliveryPinsTheAllowedLanAddressAndNeverFollowsRedirects(): void
    {
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('http://192.168.1.2/hook');
        $webhook->setSecretHash('legacy-key');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://192.168.1.2/hook', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame('', $options['proxy']);
            self::assertSame(['192.168.1.2' => '192.168.1.2'], $options['resolve']);
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/']]);
        });
        $service = new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), new JsonEncoder(), new WebhookDestinationPolicy(['192.168.1.2']));
        $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-id', Uuid::generate());
        self::assertSame(1, $client->getRequestsCount());
    }
}
