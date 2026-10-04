<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use App\Shared\Domain\Model\Uuid;
use App\Notification\Interface\Controller\WebhookController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookSigningVersionTest extends TestCase
{
    public function testDeliveredSignatureUsesTheOriginalSecretBeforeAndAfterRotation(): void
    {
        $secret = 'original-secret';
        $codec = new WebhookSecretCodec('test-app-secret');
        $webhook = new WebhookEntity(Uuid::generate(), $codec->encrypt($secret));
        $webhook->setUrl('https://1.1.1.1/hook');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $repository->method('find')->willReturn($webhook);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(3))->method('getRepository')->willReturn($repository);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(3))->method('flush');
        $previousSecret = 'obsolete-secret';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$secret, &$previousSecret): MockResponse {
            $headers = $options['normalized_headers'];
            self::assertSame('X-Webhook-Signature-Version: 2', $headers['x-webhook-signature-version'][0]);
            $timestamp = substr($headers['x-webhook-timestamp'][0], strlen('X-Webhook-Timestamp: '));
            $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $options['body'], $secret);
            self::assertSame('X-Webhook-Signature: ' . $expected, $headers['x-webhook-signature'][0]);
            self::assertNotSame('X-Webhook-Signature: sha256=' . hash_hmac('sha256', $timestamp . '.' . $options['body'], hash('sha256', $secret)), $headers['x-webhook-signature'][0]);
            self::assertFalse((new HmacSigner())->verify($timestamp . '.' . $options['body'], substr($headers['x-webhook-signature'][0], strlen('X-Webhook-Signature: ')), $previousSecret));
            return new MockResponse('', ['http_code' => 200]);
        });
        $service = new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), new WebhookDestinationPolicy(), $codec);
        $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-id', Uuid::generate());
        self::assertSame(1, $client->getRequestsCount());

        $previousSecret = $secret;
        $controller = new WebhookController($em, new WebhookDestinationPolicy(), $codec);
        $response = $controller->rotateSecret($webhook->getId()->toString());
        $secret = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['secret'];
        self::assertNotSame($previousSecret, $secret);
        $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-after-rotation', Uuid::generate());
        self::assertSame(2, $client->getRequestsCount());
    }
}
