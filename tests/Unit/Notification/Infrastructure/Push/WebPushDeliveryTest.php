<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Push;

use App\Notification\Application\DTO\PushSendReport;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Notification\Infrastructure\Push\WebPushDelivery;
use App\Shared\Domain\Model\Uuid;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class WebPushDeliveryTest extends TestCase
{
    public function testTheStoredKeysEncryptThePayloadAndTheAnswerIsReported(): void
    {
        $subscription = new PushSubscriptionEntity(Uuid::generate(), 'https://push.baander.app/device', 'public-key', 'auth-key', 'aesgcm');
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects(self::once())->method('sendOneNotification')->willReturnCallback(
            static function (SubscriptionInterface $target, string $payload): MessageSentReport {
                self::assertSame('https://push.baander.app/device', $target->getEndpoint());
                self::assertSame('public-key', $target->getPublicKey());
                self::assertSame('auth-key', $target->getAuthToken());
                self::assertSame('aesgcm', $target->getContentEncoding());
                self::assertSame('{"title":"Title"}', $payload);

                return new MessageSentReport(new Request('POST', $target->getEndpoint()), new Response(429), false, 'Slow down');
            },
        );

        self::assertEquals(
            new PushSendReport(false, 429),
            $this->delivery($subscription, $webPush)->send($subscription->getId(), $subscription->getUserId(), '{"title":"Title"}'),
        );
    }

    public function testATransportFailureIsAReportWithoutStatusAndKeepsTheEndpointInside(): void
    {
        $subscription = new PushSubscriptionEntity(Uuid::generate(), 'https://push.baander.app/capability-secret', 'key', 'auth', 'aes128gcm');
        $webPush = $this->createStub(WebPush::class);
        $webPush->method('sendOneNotification')->willThrowException(new \RuntimeException('Failed request to ' . $subscription->getEndpoint()));

        self::assertEquals(
            new PushSendReport(false, null),
            $this->delivery($subscription, $webPush)->send($subscription->getId(), $subscription->getUserId(), '{}'),
        );
    }

    public function testAMissingOrForeignSubscriptionIsNotSent(): void
    {
        $subscription = new PushSubscriptionEntity(Uuid::generate(), 'https://push.baander.app/device', 'key', 'auth', 'aes128gcm');
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects(self::never())->method('sendOneNotification');
        $delivery = $this->delivery($subscription, $webPush);

        self::assertNull($delivery->send(Uuid::generate(), $subscription->getUserId(), '{}'));
        self::assertNull($delivery->send($subscription->getId(), Uuid::generate(), '{}'));
    }

    public function testSubscriptionsAreListedAndRemovedById(): void
    {
        $subscription = new PushSubscriptionEntity(Uuid::generate(), 'https://push.baander.app/device', 'key', 'auth', 'aes128gcm');
        $repository = $this->createMock(PushSubscriptionRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static fn (Uuid $id): ?PushSubscriptionEntity => $id->equals($subscription->getId()) ? $subscription : null,
        );
        $repository->expects(self::once())->method('findByUser')->with($subscription->getUserId())->willReturn([$subscription]);
        $repository->expects(self::once())->method('remove')->with($subscription);
        $delivery = new WebPushDelivery($repository, $this->createStub(WebPush::class));

        self::assertEquals([$subscription->getId()], $delivery->subscriptionsOf($subscription->getUserId()));
        $delivery->remove($subscription->getId());
        $delivery->remove(Uuid::generate());
    }

    private function delivery(PushSubscriptionEntity $subscription, WebPush $webPush): WebPushDelivery
    {
        $repository = $this->createStub(PushSubscriptionRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static fn (Uuid $id): ?PushSubscriptionEntity => $id->equals($subscription->getId()) ? $subscription : null,
        );

        return new WebPushDelivery($repository, $webPush);
    }
}
