<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class WebhookFailurePropagationTest extends TestCase
{
    #[DataProvider('transientStatuses')]
    public function testExhaustedTransientFailuresReachMessenger(?int $status): void
    {
        $webhook = $this->webhook('transient');
        $failure = new TransportException('Network unavailable');
        $attempts = 0;
        $client = new MockHttpClient(static function () use ($status, $failure, &$attempts): MockResponse {
            ++$attempts;
            if ($status === null) {
                throw $failure;
            }
            return new MockResponse('', ['http_code' => $status]);
        });
        $service = $this->service([$webhook], $client);
        $exception = $this->failure($service);
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertSame(3, $attempts);
        if ($status === null) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /** @return iterable<string, array{int|null}> */
    public static function transientStatuses(): iterable
    {
        yield 'timeout' => [408];
        yield 'rate limit' => [429];
        yield 'server error' => [503];
        yield 'network failure' => [null];
    }

    #[DataProvider('permanentStatuses')]
    public function testPermanentStatusFailsWithoutRetry(int $status): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => $status]));
        $exception = $this->failure($this->service([$this->webhook('permanent')], $client));
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertSame(1, $client->getRequestsCount());
    }

    /** @return iterable<string, array{int}> */
    public static function permanentStatuses(): iterable
    {
        yield 'redirect' => [302];
        yield 'invalid payload' => [400];
        yield 'unauthorized' => [401];
        yield 'not found' => [404];
    }

    public function testUnresolvedDestinationFailsAfterHealthyDeliveryWithoutSendingBlockedRequest(): void
    {
        $blocked = $this->webhook('blocked');
        $healthy = $this->webhook('healthy');
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://healthy.baander.app/hook', $url);
            return new MockResponse('', ['http_code' => 200]);
        });
        $exception = $this->failure($this->service([$blocked, $healthy], $client));
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testTemporaryEmptyDnsResultIsRetryableWithoutSendingHttp(): void
    {
        $client = new MockHttpClient([]);
        $policy = new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => []);
        $exception = $this->failure($this->service([$this->webhook('dns-unavailable')], $client, policy: $policy));
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testTransientFailureTakesPriorityOverPermanentFailureAndAllDestinationsAreAttempted(): void
    {
        $attempts = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$attempts): MockResponse {
            $attempts[] = $url;
            return new MockResponse('', ['http_code' => str_contains($url, 'transient') ? 503 : (str_contains($url, 'permanent') ? 403 : 200)]);
        });
        $exception = $this->failure($this->service([$this->webhook('permanent'), $this->webhook('transient'), $this->webhook('healthy')], $client));
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertCount(5, $attempts);
        self::assertSame('https://healthy.baander.app/hook', $attempts[4]);
        self::assertStringContainsString('2', $exception->getMessage());
    }

    public function testSecretDecryptionFailureReachesMessengerAfterHealthyDelivery(): void
    {
        $encrypted = $this->webhook('encrypted');
        $encrypted->setEncryptedSigningSecret('invalid-ciphertext');
        $failure = new \RuntimeException('Secret unavailable');
        $secrets = $this->createStub(WebhookSecretPortInterface::class);
        $secrets->method('decrypt')->willReturnCallback(static function (string $ciphertext) use ($failure): string {
            if ($ciphertext === 'invalid-ciphertext') {
                throw $failure;
            }
            return (new WebhookSecretCodec('test-app-secret'))->decrypt($ciphertext);
        });
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://healthy.baander.app/hook', $url);
            return new MockResponse('', ['http_code' => 200]);
        });
        $exception = $this->failure($this->service([$encrypted, $this->webhook('healthy')], $client, secrets: $secrets));
        self::assertSame($failure, $exception->getPrevious());
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testLogPersistenceFailureDoesNotRetrySuccessfulHttpDeliveryAndStillAttemptsHealthyDestination(): void
    {
        $failure = new \RuntimeException('Delivery log database unavailable');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('flush')->willReturnCallback(static function () use ($failure): void {
            static $calls = 0;
            if (++$calls === 1) {
                throw $failure;
            }
        });
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $exception = $this->failure($this->service([$this->webhook('first'), $this->webhook('healthy')], $client, $em));
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testIdempotencyKeyIsStableAcrossRetriesAndMessengerRedeliveryAndDistinctPerWebhookAndNotification(): void
    {
        $first = $this->webhook('first');
        $second = $this->webhook('second');
        $keys = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$keys): MockResponse {
            $keys[] = $options['normalized_headers']['idempotency-key'][0] ?? null;
            return new MockResponse('', ['http_code' => count($keys) === 1 ? 500 : 200]);
        });
        $service = $this->service([$first, $second], $client);
        $this->deliver($service);
        $this->deliver($service);
        $this->deliver($service, 'different-notification');
        self::assertCount(7, $keys);
        self::assertSame('Idempotency-Key: '.hash('sha256', 'notification-1:'.$first->getId()->toString()), $keys[0]);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame($keys[0], $keys[3]);
        self::assertSame($keys[2], $keys[4]);
        self::assertNotSame($keys[0], $keys[2]);
        self::assertNotSame($keys[0], $keys[5]);
    }

    private function webhook(string $host): WebhookEntity
    {
        $webhook = new WebhookEntity(Uuid::generate(), (new WebhookSecretCodec('test-app-secret'))->encrypt('original-secret'));
        $webhook->setUrl('https://'.$host.'.baander.app/hook');
        return $webhook;
    }

    /** @param list<WebhookEntity> $webhooks */
    private function service(array $webhooks, MockHttpClient $client, (EntityManagerInterface&Stub)|null $em = null, ?WebhookSecretPortInterface $secrets = null, ?WebhookDestinationPolicy $policy = null): WebhookDeliveryService
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn($webhooks);
        $em ??= $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $policy ??= new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => $host === 'blocked.baander.app' ? ['127.0.0.1'] : ['1.1.1.1']);
        return new WebhookDeliveryService($em, $client, new HmacSigner(), new NullLogger(), $policy, $secrets ?? new WebhookSecretCodec('test-app-secret'));
    }

    private function deliver(WebhookDeliveryService $service, string $notificationId = 'notification-1'): void
    {
        $service->deliverAll('Title', 'Body', NotificationCategory::Security, $notificationId, Uuid::generate());
    }

    private function failure(WebhookDeliveryService $service): \Throwable
    {
        try {
            $this->deliver($service);
        } catch (\Throwable $exception) {
            return $exception;
        }
        self::fail('Webhook delivery failure must reach Messenger.');
    }
}
