<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\PushSendReport;
use App\Shared\Domain\Model\Uuid;

/** The stored push subscriptions and the push service that delivers to them. */
interface PushDeliveryPortInterface
{
    /**
     * The user's subscriptions, newest first, at most SUBSCRIPTIONS_PER_USER of them.
     *
     * @return list<Uuid>
     */
    public function subscriptionsOf(Uuid $userId): array;

    /**
     * Sends the encrypted payload to the subscription. Null when the subscription no longer
     * exists or belongs to another user. A transport failure is a report without a status;
     * the endpoint and the transport's error stay inside the adapter.
     */
    public function send(Uuid $subscriptionId, Uuid $userId, string $payload): ?PushSendReport;

    public function remove(Uuid $subscriptionId): void;

    /** Whether the server has the VAPID key pair push messages are signed with; without it nothing can be sent. */
    public function isConfigured(): bool;
}
