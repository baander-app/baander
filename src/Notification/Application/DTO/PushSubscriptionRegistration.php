<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class PushSubscriptionRegistration
{
    public function __construct(
        public string $endpoint,
        public string $publicKey,
        public string $authKey,
        public string $contentEncoding,
        public ?string $userAgent = null,
    ) {
    }
}
