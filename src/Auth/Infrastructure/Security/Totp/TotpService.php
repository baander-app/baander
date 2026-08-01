<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Totp;

use OTPHP\TOTP;

final class TotpService
{
    public function __construct(
        private readonly string $issuer = 'Baander',
        private readonly int $window = 1,
    ) {
    }

    /**
     * Generate a new TOTP secret.
     *
     * @return string The base32-encoded secret
     */
    public function generateSecret(): string
    {
        return TOTP::create()->getSecret();
    }

    /**
     * Verify a TOTP code against a given secret.
     *
     * @param string $secret The base32-encoded shared secret
     * @param string $code   The 6-digit TOTP code to verify
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $totp = TOTP::create($secret);

        // Allow a configurable window for clock skew
        return $totp->verify($code, null, $this->window);
    }

    /**
     * Get the otpauth:// URI for provisioning an authenticator app (QR code).
     *
     * @param string $secret The base32-encoded shared secret
     * @param string $email  The user's email address (used as the account label)
     */
    public function getProvisioningUri(string $secret, string $email): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($email);
        // issuer should be set from configuration; using a default for now
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }
}
