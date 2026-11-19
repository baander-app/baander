<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use RuntimeException;

/** A production process stops before booting because a required secret is unsafe. */
final class ProductionSecretsRefusedException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            'Refusing to boot in production: ' . implode(' ', $problems)
            . ' Set each to a private random value of at least 32 bytes (AUTH_TOTP_ENCRYPTION_KEY: 32 random bytes, base64-encoded).',
        );
    }
}
