<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Shared\Domain\Model\Uuid;

interface PushSubscriptionRegistrationPortInterface
{
    /** A user's subscriptions beyond this many are evicted, longest-registered first. */
    public const int SUBSCRIPTIONS_PER_USER = 20;

    public const int ENDPOINT_LENGTH = 2048;

    /** Base64url of a 65-byte P-256 point is 87 characters; the bound leaves room for padding. */
    public const int PUBLIC_KEY_LENGTH = 128;

    /** Base64url of the 16-byte authentication secret is 22 characters. */
    public const int AUTH_KEY_LENGTH = 64;

    public const int USER_AGENT_LENGTH = 255;

    /**
     * Create an unclaimed endpoint or rotate its owner's delivery credentials.
     * Other owners cannot claim it. A created subscription beyond SUBSCRIPTIONS_PER_USER
     * evicts the owner's longest-registered ones in the same transaction. Does not flush
     * unrelated pending work or commit an existing caller transaction.
     */
    public function registerForUser(Uuid $ownerId, PushSubscriptionRegistration $subscription): PushSubscriptionRegistrationResult;
}
