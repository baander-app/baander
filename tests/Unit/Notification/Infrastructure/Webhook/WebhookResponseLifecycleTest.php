<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Shared\Domain\Model\Uuid;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WebhookResponseLifecycleTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function finalStatuses(): iterable
    {
        yield 'accepted' => [200];
        yield 'permanent rejection' => [400];
    }

    #[DataProvider('finalStatuses')]
    public function testRetainedResponseIsCanceledBeforePersistenceAndBufferingIsDisabled(int $status): void
    {
        $response = null;
        $optionsSeen = [];
        $canceledAtPersistence = null;
        $realClient = new MockHttpClient(new MockResponse('ignored response body', ['http_code' => $status]));
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use ($realClient, &$response, &$optionsSeen): ResponseInterface {
            $optionsSeen = $options;
            return $response = $realClient->request($method, $url, $options);
        });
        $service = $this->service($client, static function () use (&$response, &$canceledAtPersistence): void {
            $canceledAtPersistence = $response?->getInfo('canceled');
        });

        try {
            $this->deliver($service);
        } catch (UnrecoverableMessageHandlingException $error) {
            self::assertSame(400, $status, $error->getMessage());
        }

        self::assertTrue($canceledAtPersistence, 'The accepted or rejected response must be closed before persistence.');
        self::assertFalse($optionsSeen['buffer'] ?? true, 'Webhook response bodies are unused and must not be buffered.');
        self::assertTrue($response?->getInfo('canceled'));
    }

    public function testRetryClosesPreviousResponseBeforeSendingAgain(): void
    {
        $responses = [];
        $previousCanceledAtRetry = null;
        $allCanceledAtPersistence = null;
        $realClient = new MockHttpClient([new MockResponse('retry body', ['http_code' => 503]), new MockResponse('accepted body', ['http_code' => 200])]);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use ($realClient, &$responses, &$previousCanceledAtRetry): ResponseInterface {
            if ($responses !== []) {
                $previousCanceledAtRetry = $responses[0]->getInfo('canceled');
            }
            return $responses[] = $realClient->request($method, $url, $options);
        });
        $service = $this->service($client, static function () use (&$responses, &$allCanceledAtPersistence): void {
            $allCanceledAtPersistence = array_map(static fn (ResponseInterface $response): mixed => $response->getInfo('canceled'), $responses);
        });

        $this->deliver($service);

        self::assertTrue($previousCanceledAtRetry);
        self::assertSame([true, true], $allCanceledAtPersistence);
    }

    public function testStatusInitializationFailureStillCancelsResponseBeforeRetry(): void
    {
        $canceled = false;
        $canceledAtRetry = null;
        $failed = $this->createMock(ResponseInterface::class);
        $failed->method('getStatusCode')->willThrowException(new TransportException('Headers unavailable'));
        $failed->expects(self::once())->method('cancel')->willReturnCallback(static function () use (&$canceled): void { $canceled = true; });
        $accepted = $this->createMock(ResponseInterface::class);
        $accepted->method('getStatusCode')->willReturn(200);
        $accepted->expects(self::once())->method('cancel');
        $attempt = 0;
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(static function () use ($failed, $accepted, &$attempt, &$canceled, &$canceledAtRetry): ResponseInterface {
            if (++$attempt === 1) {
                return $failed;
            }
            $canceledAtRetry = $canceled;
            return $accepted;
        });

        $this->deliver($this->service($client));

        self::assertTrue($canceledAtRetry);
    }

    public function testRequestFailureWithoutResponseCanRetryNormally(): void
    {
        $accepted = $this->createMock(ResponseInterface::class);
        $accepted->method('getStatusCode')->willReturn(200);
        $accepted->expects(self::once())->method('cancel');
        $attempt = 0;
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(static function () use ($accepted, &$attempt): ResponseInterface {
            if (++$attempt === 1) {
                throw new TransportException('Connection unavailable');
            }
            return $accepted;
        });

        $this->deliver($this->service($client));
    }

    private function service(HttpClientInterface $client, ?Closure $onPersist = null): WebhookDeliveryService
    {
        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl('https://webhook.baander.app/hook');
        $webhook->setSecretHash('fixture secret');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$webhook]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback($onPersist ?? static function (): void {});
        $entityManager->expects(self::once())->method('flush');
        $policy = new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => ['1.1.1.1']);

        return new WebhookDeliveryService($entityManager, $client, new HmacSigner(), new NullLogger(), $policy);
    }

    private function deliver(WebhookDeliveryService $service): void
    {
        $service->deliverAll('Title', 'Body', NotificationCategory::Security, 'notification-lifecycle', Uuid::generate());
    }
}
