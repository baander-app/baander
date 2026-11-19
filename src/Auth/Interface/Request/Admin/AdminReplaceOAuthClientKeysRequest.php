<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminReplaceOAuthClientKeysRequest',
    description: 'Exactly one of `jwks` and `jwksUri`. It may differ from the client\'s current key source.',
    properties: [
        new OA\Property(
            property: 'jwks',
            description: 'The client\'s public keys as an inline JWK Set: at most 10 public asymmetric keys, each with its own `kid` when there are several, at most 64 KB',
            type: 'object',
            nullable: true,
            example: ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'signing-1', 'x' => 'WKn-ZIGevcwGIyyrzFoZNBdaq9_TsqzGl96oc0CWuis', 'y' => 'y77t-RvAHRKTsSGdIYUfweuOvwrvDD-Q3Hv5J0fSKbE']]],
        ),
        new OA\Property(property: 'jwksUri', description: 'An https URI on port 443 where the client publishes its JWK Set', type: 'string', format: 'uri', nullable: true, example: 'https://server.baander.app/jwks.json'),
    ],
)]
final readonly class AdminReplaceOAuthClientKeysRequest
{
    /** @param array<string, mixed>|null $jwks */
    public function __construct(
        public ?array $jwks = null,
        public ?string $jwksUri = null,
    ) {
    }
}
