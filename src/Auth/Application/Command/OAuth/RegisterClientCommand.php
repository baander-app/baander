<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * An administrator registers a device, public or confidential OAuth client.
 *
 * The handler answers with a RegisteredClientDTO; a confidential client's
 * secret appears there and nowhere else. A confidential client authenticates
 * with a secret unless `authMethod` is `private_key_jwt`, which takes exactly
 * one key source: an inline JWK Set or an https `jwks_uri`.
 */
final readonly class RegisterClientCommand
{
    /**
     * @param string $type device, public or confidential (ClientType values)
     * @param list<string> $redirectUris Required for public and confidential clients; must be empty for device clients
     * @param string|null $authMethod client_secret_post or private_key_jwt for a confidential client; null means client_secret_post
     * @param array<array-key, mixed>|null $jwks The inline JWK Set of a private_key_jwt client
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $redirectUris = [],
        public ?string $authMethod = null,
        public ?array $jwks = null,
        public ?string $jwksUri = null,
    ) {
    }
}
