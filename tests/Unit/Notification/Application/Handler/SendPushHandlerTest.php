<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Handler\SendPushHandler;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Application\Port\PushDeliveryPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SendPushHandlerTest extends TestCase
{
    public function testEachSubscriptionReceivesItsOwnMessageWithTheNotification(): void
    {
        $command = $this->command();
        $subscriptions = [Uuid::generate(), Uuid::generate(), Uuid::generate()];
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::once())->method('subscriptionsOf')->with($command->userId)->willReturn($subscriptions);
        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        $this->handler($delivery, $bus)($command);

        self::assertEquals(
            array_map(static fn (Uuid $subscription): SendPushToSubscriptionCommand => new SendPushToSubscriptionCommand(
                $subscription,
                $command->userId,
                'Title',
                'Body',
                'notification-1',
            ), $subscriptions),
            $dispatched,
        );
    }

    public function testAFailedDispatchReachesMessengerSoTheBatchIsRetried(): void
    {
        $command = $this->command();
        $delivery = $this->createStub(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->method('subscriptionsOf')->willReturn([Uuid::generate()]);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Transport unavailable'));

        $this->expectExceptionMessage('Transport unavailable');

        $this->handler($delivery, $bus)($command);
    }

    public function testDisabledPushPreferenceSkipsTheSubscriptions(): void
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::never())->method('subscriptionsOf');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->handler($delivery, $bus, enabled: false)($this->command());
    }

    public function testServerWidePushToggleOffSkipsDeliveryAndRecordsTheSkip(): void
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->expects(self::never())->method('subscriptionsOf');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('push notifications are turned off'),
            self::callback(static fn (array $context): bool => $context['notification_id'] === 'notification-1'
                && $context['setting'] === NotificationSettingDefinitions::PUSH_ENABLED),
        );

        $this->handler($delivery, $bus, pushEnabled: false, logger: $logger)($this->command());
    }

    public function testAServerWithoutAVapidKeyPairSkipsDeliveryAndRecordsTheSkip(): void
    {
        $delivery = $this->createMock(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(false);
        $delivery->expects(self::never())->method('subscriptionsOf');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('no VAPID key pair'),
            self::callback(static fn (array $context): bool => $context['notification_id'] === 'notification-1'),
        );

        $this->handler($delivery, $bus, logger: $logger)($this->command());
    }

    public function testServerWidePushToggleIsReadForEveryMessage(): void
    {
        $command = $this->command();
        $delivery = $this->createStub(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->method('subscriptionsOf')->willReturn([Uuid::generate()]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::exactly(2))->method('get')
            ->with(NotificationSettingDefinitions::PUSH_ENABLED)
            ->willReturnOnConsecutiveCalls(false, true);
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $handler = new SendPushHandler($preferences, $delivery, $bus, new NullLogger(), $settings);

        $handler($command);
        $handler($command);
    }

    public function testNoSubscriptionsDispatchNothing(): void
    {
        $delivery = $this->createStub(PushDeliveryPortInterface::class);
        $delivery->method('isConfigured')->willReturn(true);
        $delivery->method('subscriptionsOf')->willReturn([]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->handler($delivery, $bus)($this->command());
    }

    private function handler(
        PushDeliveryPortInterface $delivery,
        MessageBusInterface $bus,
        bool $enabled = true,
        bool $pushEnabled = true,
        ?LoggerInterface $logger = null,
    ): SendPushHandler {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn($enabled);
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnMap([[NotificationSettingDefinitions::PUSH_ENABLED, $pushEnabled]]);

        return new SendPushHandler($preferences, $delivery, $bus, $logger ?? new NullLogger(), $settings);
    }

    private function command(): SendPushCommand
    {
        return new SendPushCommand(Uuid::generate(), NotificationCategory::Security, 'Title', 'Body', 'notification-1');
    }
}
