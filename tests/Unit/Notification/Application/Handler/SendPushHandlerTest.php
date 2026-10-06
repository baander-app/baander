<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\Handler\SendPushHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SendPushHandlerTest extends TestCase
{
    public function testTransportAndReportFailuresReachMessengerAfterAllSubscriptionsAreAttempted(): void
    {
        $entities = [$this->subscription('first'), $this->subscription('second'), $this->subscription('healthy')];
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->method('findByUser')->willReturn($entities);
        $repository->expects($this->never())->method('remove');
        $failure = new \RuntimeException('Push transport unavailable');
        $attempted = [];
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->exactly(3))->method('sendOneNotification')->willReturnCallback(
            function (SubscriptionInterface $subscription, string $payload) use (&$attempted, $failure): MessageSentReport {
                $endpoint = $subscription->getEndpoint();
                $attempted[] = $endpoint;
                self::assertSame('Title', json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['title']);
                if (str_ends_with($endpoint, '/first')) {
                    throw $failure;
                }
                return new MessageSentReport(new Request('POST', $endpoint), new Response(str_ends_with($endpoint, '/second') ? 503 : 201), !str_ends_with($endpoint, '/second'), 'Push response');
            },
        );

        try {
            $this->handler($repository, $webPush)($this->command());
            self::fail('Push failures must reach Messenger.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringContainsString('2', $exception->getMessage());
        }
        self::assertSame(array_map(static fn (PushSubscriptionEntity $entity): string => $entity->getEndpoint(), $entities), $attempted);
    }

    #[DataProvider('failedReports')]
    public function testFailedReportReachesMessenger(?int $status): void
    {
        $entity = $this->subscription('failed');
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->method('findByUser')->willReturn([$entity]);
        $repository->expects($this->never())->method('remove');
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->once())->method('sendOneNotification')->willReturn(
            new MessageSentReport(new Request('POST', $entity->getEndpoint()), $status === null ? null : new Response($status), false, 'Push rejected'),
        );

        $this->expectException(\RuntimeException::class);
        $this->handler($repository, $webPush)($this->command());
    }

    /** @return iterable<string, array{int|null}> */
    public static function failedReports(): iterable
    {
        yield 'authentication' => [401];
        yield 'rate limit' => [429];
        yield 'server error' => [500];
        yield 'unavailable' => [503];
        yield 'network failure report' => [null];
    }

    #[DataProvider('expiredReports')]
    public function testExpiredSubscriptionIsRemovedAndHealthySubscriptionStillSent(int $status): void
    {
        $expired = $this->subscription('expired');
        $healthy = $this->subscription('healthy');
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->method('findByUser')->willReturn([$expired, $healthy]);
        $repository->expects($this->once())->method('remove')->with($expired);
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->exactly(2))->method('sendOneNotification')->willReturnOnConsecutiveCalls(
            new MessageSentReport(new Request('POST', $expired->getEndpoint()), new Response($status), false, 'Expired'),
            new MessageSentReport(new Request('POST', $healthy->getEndpoint()), new Response(201)),
        );

        $this->handler($repository, $webPush)($this->command());
    }

    /** @return iterable<string, array{int}> */
    public static function expiredReports(): iterable
    {
        yield 'not found' => [404];
        yield 'gone' => [410];
    }

    public function testExpiredSubscriptionRemovalFailureReachesMessengerAfterHealthyDelivery(): void
    {
        $expired = $this->subscription('expired');
        $healthy = $this->subscription('healthy');
        $failure = new \RuntimeException('Database unavailable');
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->method('findByUser')->willReturn([$expired, $healthy]);
        $repository->expects($this->once())->method('remove')->with($expired)->willThrowException($failure);
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->exactly(2))->method('sendOneNotification')->willReturnOnConsecutiveCalls(
            new MessageSentReport(new Request('POST', $expired->getEndpoint()), new Response(410), false, 'Expired'),
            new MessageSentReport(new Request('POST', $healthy->getEndpoint()), new Response(201)),
        );

        try {
            $this->handler($repository, $webPush)($this->command());
            self::fail('Subscription cleanup failure must reach Messenger.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testDisabledPushSkipsSubscriptionLookupAndDelivery(): void
    {
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->expects($this->never())->method('findByUser');
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->never())->method('sendOneNotification');
        $this->handler($repository, $webPush, false)($this->command());
    }

    public function testNoSubscriptionsSkipsDelivery(): void
    {
        $repository = $this->createStub(PushSubscriptionRepositoryInterface::class);
        $repository->method('findByUser')->willReturn([]);
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->never())->method('sendOneNotification');
        $this->handler($repository, $webPush)($this->command());
    }

    private function handler(PushSubscriptionRepositoryInterface $repository, WebPush $webPush, bool $enabled = true): SendPushHandler
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn($enabled);
        return new SendPushHandler($preferences, $repository, $webPush, new NullLogger(), 'baander.app', new JsonEncoder());
    }

    private function subscription(string $name): PushSubscriptionEntity
    {
        return new PushSubscriptionEntity(new Uuid(), 'https://push.baander.app/'.$name, 'public-key', 'auth-key', 'aes128gcm');
    }

    private function command(): SendPushCommand
    {
        return new SendPushCommand(Uuid::generate(), NotificationCategory::Security, 'Title', 'Body', 'notification-1');
    }
}
