<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\PublicId;

/**
 * An administrator replaces a `private_key_jwt` client's inline JWK Set or `jwks_uri`;
 * the old keys stop working at once.
 *
 * Exactly one of the two key sources is given. It may differ from the client's
 * current source, so a client can move between an inline set and a `jwks_uri`.
 */
final readonly class ReplaceClientKeysCommand
{
    /** @param array<array-key, mixed>|null $jwks */
    public function __construct(
        public PublicId $clientId,
        public ?array $jwks = null,
        public ?string $jwksUri = null,
    ) {
    }
}
