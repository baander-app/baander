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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookPayloadFormatTest extends TestCase
{
    public function testPayloadPreservesJsonBytesAndSignsTheDeliveredBody(): void
    {
        $secret = 'original-secret';
        $codec = new WebhookSecretCodec('test-app-secret');
        $webhook = $this->webhook();
        $entityManager = $this->entityManager($webhook);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($secret): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://baander.app/hook', $url);
            $payload = $options['body'];
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['title', 'body', 'category', 'notification_id', 'timestamp'], array_keys($decoded));
            self::assertSame('Música "ready"', $decoded['title']);
            self::assertSame("https://baander.app/music\nBackslash: \\ and café 😀", $decoded['body']);
            self::assertSame('security', $decoded['category']);
            self::assertSame('event-id', $decoded['notification_id']);
            $instant = new \DateTimeImmutable($decoded['timestamp']);
            self::assertSame($decoded['timestamp'], $instant->format(\DateTimeInterface::ATOM));
            self::assertEqualsWithDelta(time(), $instant->getTimestamp(), 5);

            $expectedPayload = '{"title":"M\\u00fasica \\"ready\\"","body":"https:\\/\\/baander.app\\/music\\nBackslash: \\\\ and caf\\u00e9 \\ud83d\\ude00","category":"security","notification_id":"event-id","timestamp":"' . $decoded['timestamp'] . '"}';
            self::assertSame($expectedPayload, $payload);

            $headers = $options['normalized_headers'];
            self::assertSame('Content-Type: application/json', $headers['content-type'][0]);
            self::assertSame('X-Webhook-Signature-Version: ' . 2, $headers['x-webhook-signature-version'][0]);
            $timestamp = substr($headers['x-webhook-timestamp'][0], strlen('X-Webhook-Timestamp: '));
            $key = $secret;
            $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $key);
            self::assertSame('X-Webhook-Signature: ' . $signature, $headers['x-webhook-signature'][0]);

            return new MockResponse('', ['http_code' => 200]);
        });
        $service = new WebhookDeliveryService($entityManager, $client, new HmacSigner(), new NullLogger(), $this->destinations(), $codec);

        $service->deliverAll('Música "ready"', "https://baander.app/music\nBackslash: \\ and café 😀", NotificationCategory::Security, 'event-id', Uuid::generate());

        self::assertSame(1, $client->getRequestsCount());
    }

    /** @return iterable<array{string, string, string}> */
    public static function invalidUtf8Payloads(): iterable
    {
        yield 'title' => ["\xB1", 'body', 'event-id'];
        yield 'body' => ['title', "\xB1", 'event-id'];
        yield 'notification ID' => ['title', 'body', "\xB1"];
    }

    #[DataProvider('invalidUtf8Payloads')]
    public function testInvalidUtf8FailsBeforeHttpOrPersistence(string $title, string $body, string $notificationId): void
    {
        $entityManager = $this->entityManager($this->webhook());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');
        $service = new WebhookDeliveryService($entityManager, $client, new HmacSigner(), new NullLogger(), $this->destinations(), new WebhookSecretCodec('test-app-secret'));

        try {
            $service->deliverAll($title, $body, NotificationCategory::Security, $notificationId, Uuid::generate());
            self::fail('Invalid UTF-8 must fail JSON encoding.');
        } catch (\RuntimeException $exception) {
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
            self::assertSame(JSON_ERROR_UTF8, $exception->getPrevious()->getCode());
        }
    }

    private function webhook(): WebhookEntity
    {
        $webhook = new WebhookEntity(Uuid::generate(), (new WebhookSecretCodec('test-app-secret'))->encrypt('original-secret'));
        $webhook->setUrl('https://baander.app/hook');

        return $webhook;
    }

    private function entityManager(WebhookEntity $webhook): EntityManagerInterface&MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getRepository')->with(WebhookEntity::class)->willReturn($repository);

        return $entityManager;
    }

    private function destinations(): WebhookDestinationPolicy
    {
        return new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => $host === 'baander.app' ? ['93.184.216.34'] : []);
    }
}
