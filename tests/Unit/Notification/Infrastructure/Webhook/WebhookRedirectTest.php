<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class WebhookRedirectTest extends TestCase
{
    public function testDeliveryPinsTheAllowedLanAddressAndNeverFollowsRedirects(): void
    {
        $webhook = new WebhookEntity(Uuid::generate(), (new WebhookSecretCodec('test-app-secret'))->encrypt('original-secret'));
        $webhook->setUrl('http://lan.baander.app/hook');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://lan.baander.app/hook', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame('*', $options['no_proxy']);
            self::assertArrayNotHasKey('proxy', $options);
            self::assertSame(['lan.baander.app' => '192.168.1.2'], $options['resolve']);
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/']]);
        });
        $service = new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), new WebhookDestinationPolicy(['192.168.1.2'], dnsResolver: static fn (string $host): array => ['192.168.1.2']), new WebhookSecretCodec('test-app-secret'));
        $failure = null;
        try {
            $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-id', Uuid::generate());
        } catch (UnrecoverableMessageHandlingException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testDeliveryOptionsAreAcceptedByNativeClientWithAConfiguredProxy(): void
    {
        $webhook = new WebhookEntity(Uuid::generate(), (new WebhookSecretCodec('test-app-secret'))->encrypt('original-secret'));
        $webhook->setUrl('http://lan.baander.app/hook');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $deliveryOptions = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$deliveryOptions): MockResponse {
            $deliveryOptions = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        $service = new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), new WebhookDestinationPolicy(['192.168.1.2'], dnsResolver: static fn (string $host): array => ['192.168.1.2']), new WebhookSecretCodec('test-app-secret'));
        $service->deliverAll('title', 'body', NotificationCategory::Security, 'event-id', Uuid::generate());

        self::assertIsArray($deliveryOptions);
        $deliveryOptions['resolve'] = ['lan.baander.app' => '127.0.0.1'];
        $deliveryOptions['timeout'] = 1;
        $deliveryOptions['max_duration'] = 1;
        $native = new NativeHttpClient(['proxy' => 'http://127.0.0.1:2']);

        try {
            $native->request('POST', 'http://lan.baander.app:1/hook', $deliveryOptions)->getStatusCode();
            self::fail('The closed local port unexpectedly accepted the request.');
        } catch (TransportExceptionInterface $e) {
            self::assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }
    }
}
