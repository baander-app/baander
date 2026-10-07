<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * What the consent page shows: the requesting client and the scopes a grant would carry.
 */
final readonly class AuthorizationRequestDTO
{
    /** @param string[] $scopes */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public string $clientType,
        public array $scopes,
        public string $redirectUri,
        public bool $consentRequired,
    ) {
    }
}
