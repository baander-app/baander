<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Totp;

use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use InvalidArgumentException;

/**
 * libsodium secretbox (XSalsa20-Poly1305) under AUTH_TOTP_ENCRYPTION_KEY, a dedicated
 * 32-byte key, so rotating APP_SECRET leaves TOTP working. The stored value is the
 * random 24-byte nonce followed by the box.
 */
final readonly class SodiumTotpSecretCipher implements TotpSecretCipherInterface
{
    private string $key;

    /** @param string $encodedKey The 32-byte key, base64-encoded */
    public function __construct(#[\SensitiveParameter] string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'AUTH_TOTP_ENCRYPTION_KEY must be %d bytes, base64-encoded.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }
        $this->key = $key;
    }

    public function seal(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($secret, $nonce, $this->key);
    }

    public function open(string $ciphertext): string
    {
        $secret = strlen($ciphertext) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(
                substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key,
            )
            : false;

        return $secret === false ? throw TotpSecretUnavailableException::forStoredSecret() : $secret;
    }
}
