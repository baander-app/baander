<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * An issued authorization code and the redirect URI it was issued for.
 */
final readonly class AuthorizationCodeDTO
{
    public function __construct(
        public string $code,
        public string $redirectUri,
    ) {
    }
}
