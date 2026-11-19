<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use DateTimeImmutable;

/** How many expired codes and pushed requests a purge deleted, and the expiry cutoff it applied. */
final readonly class PurgedOAuthCodesDTO
{
    public function __construct(
        public int $authorizationCodes,
        public int $deviceCodes,
        public int $pushedAuthorizationRequests,
        public DateTimeImmutable $expiredBefore,
    ) {
    }
}
