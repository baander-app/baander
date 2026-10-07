<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SendPushHandler
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly PushSubscriptionRepositoryInterface $subscriptionRepository,
        private readonly WebPush $webPush,
        private readonly LoggerInterface $logger,
        private readonly string $appDomain,
        private readonly JsonEncoder $jsonEncoder,
        private readonly SystemSettingsPortInterface $systemSettings,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    #[AsMessageHandler(fromTransport: 'async')]
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

        if (!$this->preferenceRepository->isEnabled(
            $command->userId,
            $command->category,
            NotificationChannel::Push,
        )) {
            return;
        }

        $subscriptions = $this->subscriptionRepository->findByUser($command->userId);

        if ($subscriptions === []) {
            return;
        }

        $payload = $this->jsonEncoder->encode([
            'title' => $command->title,
            'body' => $command->body,
            'icon' => '/icons/notification.png',
            'url' => sprintf('https://%s/notifications/%s', $this->appDomain, $command->notificationPublicId),
        ], 'json');

        $failureCount = 0;
        $firstFailure = null;

        foreach ($subscriptions as $entity) {
            try {
                $this->sendToSubscription($entity, $payload);
            } catch (\Throwable $e) {
                ++$failureCount;
                $firstFailure ??= $e;
                $this->logger->error('Failed to send push notification.', [
                    'channel' => 'notification.push',
                    'notification_id' => $command->notificationPublicId,
                    'endpoint' => $entity->getEndpoint(),
                    'exception' => $e,
                ]);
            }
        }

        if ($firstFailure !== null) {
            throw new \RuntimeException(sprintf('Push delivery failed for %d subscription(s).', $failureCount), 0, $firstFailure);
        }
    }

    private function sendToSubscription(PushSubscriptionEntity $entity, string $payload): void
    {
        $subscription = Subscription::create([
            'endpoint' => $entity->getEndpoint(),
            'keys' => [
                'p256dh' => $entity->getPublicKey(),
                'auth' => $entity->getAuthKey(),
            ],
            'contentEncoding' => $entity->getContentEncoding(),
        ]);

        $report = $this->webPush->sendOneNotification($subscription, $payload);

        if ($report->isSuccess()) {
            return;
        }

        if ($report->isSubscriptionExpired()) {
            $this->subscriptionRepository->remove($entity);
            return;
        }

        throw new \RuntimeException(sprintf('Push service rejected notification: %s', $report->getReason()));
    }
}
