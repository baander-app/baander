<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\PushSendReport;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Handler\SendPushToSubscriptionHandler;
use App\Notification\Application\Port\PushDeliveryPortInterface;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SendPushToSubscriptionHandlerTest extends TestCase
{
    public function testTheNotificationIsSentToItsSubscriptionAndNeedsNothingMoreOnceDelivered(): void
    {
        $command = $this->command();
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::once())->method('send')->willReturnCallback(
            static function (Uuid $subscription, Uuid $user, string $payload) use ($command): PushSendReport {
                self::assertTrue($subscription->equals($command->subscriptionId));
                self::assertTrue($user->equals($command->userId));
                self::assertSame([
                    'title' => 'Title',
                    'body' => 'Body',
                    'icon' => '/icons/notification.png',
                    'url' => 'https://baander.app/notifications/notification-1',
                ], json_decode($payload, true, flags: JSON_THROW_ON_ERROR));

                return new PushSendReport(true, 201);
            },
        );
        $delivery->expects(self::never())->method('remove');

        $this->handler($delivery)($command);
    }

    /** @return iterable<string, array{int}> */
    public static function expiredStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'gone' => [410];
    }

    #[DataProvider('expiredStatuses')]
    public function testAnExpiredSubscriptionIsRemovedAndNotRetried(int $status): void
    {
        $command = $this->command();
        $delivery = $this->answering($status);
        $delivery->expects(self::once())->method('remove')->with($command->subscriptionId);

        $this->handler($delivery)($command);
    }

    /** @return iterable<string, array{int}> */
    public static function refusedStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
        yield 'payload too large' => [413];
    }

    #[DataProvider('refusedStatuses')]
    public function testAnyOtherClientErrorIsUnrecoverable(int $status): void
    {
        $delivery = $this->answering($status);
        $delivery->expects(self::never())->method('remove');

        try {
            $this->handler($delivery)($this->command());
            self::fail('A refused notification must not be retried.');
        } catch (UnrecoverableMessageHandlingException $refusal) {
            self::assertSame(sprintf('Push service refused the notification with HTTP status %d.', $status), $refusal->getMessage());
        }
    }

    /** @return iterable<string, array{int|null}> */
    public static function retryableStatuses(): iterable
    {
        yield 'request timeout' => [408];
        yield 'rate limit' => [429];
        yield 'server error' => [500];
        yield 'unavailable' => [503];
        yield 'no answer' => [null];
    }

    #[DataProvider('retryableStatuses')]
    public function testAFailureThePushServiceMayRecoverFromIsRetried(?int $status): void
    {
        $command = $this->command();
        $delivery = $this->answering($status);
        $delivery->expects(self::never())->method('remove');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to send push notification.', [
            'channel' => 'notification.push',
            'notification_id' => 'notification-1',
            'subscription_id' => $command->subscriptionId->toString(),
            'status' => $status,
        ]);

        try {
            $this->handler($delivery, logger: $logger)($command);
            self::fail('The failure must reach Messenger for a retry.');
        } catch (\RuntimeException $failure) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
            self::assertSame('Push delivery failed for one subscription.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testARemovedOrReassignedSubscriptionIsSkipped(): void
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::once())->method('send')->willReturn(null);
        $delivery->expects(self::never())->method('remove');

        $this->handler($delivery)($this->command());
    }

    public function testAServerWithoutAVapidKeyPairSendsNothingAndRecordsTheSkip(): void
    {
        $command = $this->command();
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(false);
        $delivery->expects(self::never())->method('send');
        $delivery->expects(self::never())->method('remove');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('no VAPID key pair'),
            self::callback(static fn (array $context): bool => $context['subscription_id'] === $command->subscriptionId->toString()),
        );

        $this->handler($delivery, logger: $logger)($command);
    }

    public function testARetryAfterPushWasTurnedOffSendsNothing(): void
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::never())->method('send');

        $this->handler($delivery, pushEnabled: false)($this->command());
    }

    private function handler(
        PushDeliveryPortInterface $delivery,
        bool $pushEnabled = true,
        ?LoggerInterface $logger = null,
    ): SendPushToSubscriptionHandler {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnMap([[NotificationSettingDefinitions::PUSH_ENABLED, $pushEnabled]]);

        return new SendPushToSubscriptionHandler($delivery, $logger ?? new NullLogger(), 'baander.app', new JsonEncoder(), $settings);
    }

    private function answering(?int $status): PushDeliveryPortInterface&MockObject
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->method('send')->willReturn(new PushSendReport(false, $status));

        return $delivery;
    }

    private function command(): SendPushToSubscriptionCommand
    {
        return new SendPushToSubscriptionCommand(Uuid::generate(), Uuid::generate(), 'Title', 'Body', 'notification-1');
    }
}
