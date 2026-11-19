<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\TotpSecretUnavailableException;

/** Seals TOTP secrets for storage under the installation's TOTP encryption key. */
interface TotpSecretCipherInterface
{
    public function seal(string $secret): string;

    /**
     * @throws TotpSecretUnavailableException when the ciphertext does not open under the configured key
     */
    public function open(string $ciphertext): string;
}
