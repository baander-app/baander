<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Domain\Model\Uuid;
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
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(SendPushCommand $command): void
    {
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

        foreach ($subscriptions as $entity) {
            $this->sendToSubscription($entity, $payload);
        }
    }

    private function sendToSubscription(PushSubscriptionEntity $entity, string $payload): void
    {
        try {
            $subscription = Subscription::create([
                'endpoint' => $entity->getEndpoint(),
                'keys' => [
                    'p256dh' => $entity->getPublicKey(),
                    'auth' => $entity->getAuthKey(),
                ],
                'contentEncoding' => $entity->getContentEncoding(),
            ]);

            $this->webPush->sendOneNotification($subscription, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification.', [
                'channel' => 'notification.push',
                'endpoint' => $entity->getEndpoint(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
