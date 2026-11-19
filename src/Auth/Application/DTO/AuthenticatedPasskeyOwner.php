<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/**
 * The user a verified passkey assertion signs in, with the credential epoch read before the
 * passkey itself was read, so a password reset that removes the passkey also voids the login.
 */
final readonly class AuthenticatedPasskeyOwner
{
    public function __construct(
        public Uuid $userId,
        public int $credentialEpoch,
    ) {
    }
}
