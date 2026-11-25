<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Application\DTO\PushSendReport;
use App\Notification\Application\Port\PushDeliveryPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final readonly class WebPushDelivery implements PushDeliveryPortInterface
{
    public function __construct(
        private PushSubscriptionRepositoryInterface $subscriptions,
        private WebPush $webPush,
    ) {
    }

    public function subscriptionsOf(Uuid $userId): array
    {
        return array_map(
            static fn (PushSubscriptionEntity $subscription): Uuid => $subscription->getId(),
            $this->subscriptions->findByUser($userId),
        );
    }

    public function send(Uuid $subscriptionId, Uuid $userId, string $payload): ?PushSendReport
    {
        $subscription = $this->subscriptions->find($subscriptionId);
        if ($subscription === null || !$subscription->getUserId()->equals($userId)) {
            return null;
        }

        try {
            $report = $this->webPush->sendOneNotification(self::target($subscription), $payload);
        } catch (\Throwable) {
            // The error can name the endpoint, a capability URL.
            return new PushSendReport(false, null);
        }

        return new PushSendReport($report->isSuccess(), $report->getResponse()?->getStatusCode());
    }

    public function remove(Uuid $subscriptionId): void
    {
        $subscription = $this->subscriptions->find($subscriptionId);
        if ($subscription !== null) {
            $this->subscriptions->remove($subscription);
        }
    }

    private static function target(PushSubscriptionEntity $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->getEndpoint(),
            'keys' => [
                'p256dh' => $subscription->getPublicKey(),
                'auth' => $subscription->getAuthKey(),
            ],
            'contentEncoding' => $subscription->getContentEncoding(),
        ]);
    }
}
