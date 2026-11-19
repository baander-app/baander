<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/**
 * Password login that passed its checks: the DPoP proof, the password and, when the
 * account has TOTP, the code.
 *
 * The password authenticator stores it on the request under this class name; the login
 * controller issues tokens only from it and spends the TOTP step while storing them.
 */
final readonly class VerifiedPasswordLogin
{
    /**
     * @param string $dpopJkt Thumbprint of the proof key the tokens are bound to
     * @param string $nextNonce Server-issued nonce for the client's next DPoP proof
     * @param int|null $totpStep The TOTP time step the code matched; null for an account without TOTP
     */
    public function __construct(
        public Uuid $userId,
        public string $dpopJkt,
        public string $nextNonce,
        public ?int $totpStep,
    ) {
    }
}
