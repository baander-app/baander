<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/**
 * A signed-in user changes their own password, confirmed by their current password and, when
 * TOTP is on, a current code. Operators set passwords with SetUserPasswordCommand instead.
 */
final readonly class ChangePasswordCommand
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $currentPassword,
        #[\SensitiveParameter]
        public string $newPassword,
        #[\SensitiveParameter]
        public ?string $totpCode = null,
        /** The access token the request was made with; its session stays signed in. */
        public ?string $currentAccessTokenId = null,
    ) {
    }
}
