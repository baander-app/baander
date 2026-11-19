<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * The answer to a pushed authorization request (RFC 9126 section 2.2).
 */
final readonly class PushedAuthorizationRequestDTO
{
    /** @param int $expiresIn Seconds the request URI stays usable */
    public function __construct(
        public string $requestUri,
        public int $expiresIn,
    ) {
    }
}
