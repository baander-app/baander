<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;

/** A redeemed email verification token: the user it was issued to and the address it verifies. */
final readonly class RedeemedEmailVerification
{
    public function __construct(
        public Uuid $userId,
        public Email $email,
    ) {
    }
}
