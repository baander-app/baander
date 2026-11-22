<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** A signed-in user opens a verification link; only the account it was sent for redeems it. */
final readonly class VerifyEmailCommand
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        /** The signed-in user who opened the link. */
        public string $userId,
        /**
         * The access token the request was made with. When the link confirms a pending email
         * change, its session stays signed in and every other session ends.
         */
        public ?string $currentAccessTokenId = null,
    ) {
    }
}
