<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Port\PushDeliveryPortInterface;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans one notification out to a message per subscription, each retried on its own, so a
 * failing endpoint never makes the others receive the notification again. A failure while
 * dispatching retries the whole batch, which may queue a subscription twice: delivery is
 * at least once.
 */
final class SendPushHandler
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly PushDeliveryPortInterface $delivery,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly SystemSettingsPortInterface $systemSettings,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SendPushCommand $command): void
    {
        // The notification already exists; only its browser push is skipped. Push has no
        // stored per-delivery outcome, so the log entry is the record of the skip.
        if ($this->systemSettings->get(NotificationSettingDefinitions::PUSH_ENABLED) !== true) {
            $this->logger->info('Push delivery skipped because push notifications are turned off for this server.', [
                'channel' => 'notification.push',
                'notification_id' => $command->notificationPublicId,
                'setting' => NotificationSettingDefinitions::PUSH_ENABLED,
            ]);

            return;
        }

        if (!$this->delivery->isConfigured()) {
            $this->logger->info('Push delivery skipped because no VAPID key pair is configured.', [
                'channel' => 'notification.push',
                'notification_id' => $command->notificationPublicId,
            ]);

            return;
        }

        if (!$this->preferenceRepository->isEnabled(
            $command->userId,
            $command->category,
            NotificationChannel::Push,
        )) {
            return;
        }

        foreach ($this->delivery->subscriptionsOf($command->userId) as $subscriptionId) {
            $this->bus->dispatch(new SendPushToSubscriptionCommand(
                subscriptionId: $subscriptionId,
                userId: $command->userId,
                title: $command->title,
                body: $command->body,
                notificationPublicId: $command->notificationPublicId,
            ));
        }
    }
}
