<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * The answer to an authorization request, delivered to the client's redirect URI (RFC 6749 section 4.1.2).
 *
 * Carries either the issued code or the access_denied error of a user who declined.
 */
final readonly class AuthorizationResponseDTO
{
    private function __construct(
        public string $redirectUri,
        public ?string $code,
        public ?string $error,
        public ?string $errorDescription,
    ) {
    }

    public static function code(string $redirectUri, string $code): self
    {
        return new self($redirectUri, $code, null, null);
    }

    public static function denied(string $redirectUri): self
    {
        return new self($redirectUri, null, 'access_denied', 'The user denied the request.');
    }
}
