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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebhookSigningVersionTest extends TestCase
{
    /** @return iterable<array{int}> */
    public static function versions(): iterable
    {
        yield [1];
        yield [2];
    }

    #[DataProvider('versions')]
    public function testDeliveredSignatureUsesTheDocumentedVersionKey(int $version): void
    {
        $secret = 'original-secret';
        $codec = new WebhookSecretCodec('test-app-secret');
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://1.1.1.1/hook');
        $webhook->setSecretHash(hash('sha256', $secret));
        if ($version === 2) {
            $webhook->setEncryptedSigningSecret($codec->encrypt($secret), hash('sha256', $secret));
        }
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($version, $secret): MockResponse {
            $headers = $options['normalized_headers'];
            self::assertSame('X-Webhook-Signature-Version: ' . $version, $headers['x-webhook-signature-version'][0]);
            $timestamp = substr($headers['x-webhook-timestamp'][0], strlen('X-Webhook-Timestamp: '));
            $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $options['body'], $version === 1 ? hash('sha256', $secret) : $secret);
            self::assertSame('X-Webhook-Signature: ' . $expected, $headers['x-webhook-signature'][0]);
            return new MockResponse('', ['http_code' => 200]);
        });
        $service = new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), new JsonEncoder(), new WebhookDestinationPolicy(), $codec);
        $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-id', Uuid::generate());
        self::assertSame(1, $client->getRequestsCount());
    }
}
