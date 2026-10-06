<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/**
 * Passkey login that passed both checks: the DPoP proof and the WebAuthn assertion.
 *
 * The passkey authenticator stores it on the request under this class name;
 * the login controller issues tokens only from it.
 */
final readonly class VerifiedPasskeyLogin
{
    /**
     * @param string $dpopJkt Thumbprint of the proof key the tokens are bound to
     * @param string $nextNonce Server-issued nonce for the client's next DPoP proof
     */
    public function __construct(
        public Uuid $userId,
        public string $dpopJkt,
        public string $nextNonce,
    ) {
    }
}
