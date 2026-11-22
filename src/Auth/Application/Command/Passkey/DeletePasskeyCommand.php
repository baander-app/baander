<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Passkey;

/**
 * A signed-in user removes one of their own passkeys, confirmed by their current password and,
 * when TOTP is on, a current code. The handler returns the credential IDs of the passkeys the
 * user keeps.
 */
final readonly class DeletePasskeyCommand
{
    public function __construct(
        public string $userId,
        public string $passkeyPublicId,
        #[\SensitiveParameter]
        public string $currentPassword,
        #[\SensitiveParameter]
        public ?string $totpCode = null,
        /** The access token the request was made with; its session stays signed in. */
        public ?string $currentAccessTokenId = null,
    ) {
    }
}
