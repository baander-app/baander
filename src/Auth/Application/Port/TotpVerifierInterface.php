<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface TotpVerifierInterface
{
    /**
     * Generate a new TOTP secret.
     *
     * @return string The base32-encoded secret
     */
    public function generateSecret(): string;

    /**
     * Verify a TOTP code against a given secret.
     *
     * @param string $secret The base32-encoded shared secret
     * @param string $code   The 6-digit TOTP code to verify
     * @param int    $window Allowable time-window drift (number of periods before/after)
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool;
}
