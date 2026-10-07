<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\OAuth\Client;

/**
 * A client after registration or secret rotation, with its secret in plain text.
 *
 * The secret is shown to the administrator once; only its digest is stored.
 */
final readonly class RegisteredClientDTO
{
    public function __construct(
        public Client $client,
        public ?string $secret,
    ) {
    }
}
