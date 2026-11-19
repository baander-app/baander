<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminCreateOAuthClientRequest',
    required: ['name', 'type'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Living room TV'),
        new OA\Property(property: 'type', type: 'string', enum: ['device', 'public', 'confidential']),
        new OA\Property(
            property: 'redirectUris',
            description: 'Required for public and confidential clients (1 to 10 URIs: https, http on a loopback host, or a private-use scheme in reverse domain form; no fragment). Must be omitted or empty for device clients.',
            type: 'array',
            items: new OA\Items(type: 'string', format: 'uri'),
            example: ['https://player.baander.app/callback'],
        ),
        new OA\Property(property: 'authMethod', description: 'How a confidential client authenticates; client_secret_post when omitted. Omit it for device and public clients.', type: 'string', enum: ['client_secret_post', 'private_key_jwt'], nullable: true),
        new OA\Property(property: 'jwks', description: 'A private_key_jwt client\'s public keys as an inline JWK Set: at most 10 public asymmetric keys, each with its own `kid` when there are several, at most 64 KB. Give this or `jwksUri`.', type: 'object', nullable: true),
        new OA\Property(property: 'jwksUri', description: 'An https URI on port 443 where a private_key_jwt client publishes its JWK Set. Give this or `jwks`.', type: 'string', format: 'uri', nullable: true, example: 'https://server.baander.app/jwks.json'),
    ],
)]
final readonly class AdminCreateOAuthClientRequest
{
    /**
     * @param list<string> $redirectUris
     * @param array<string, mixed>|null $jwks
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required.', normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $name = '',

        #[Assert\NotBlank(message: 'Type is required.')]
        #[Assert\Choice(choices: ['device', 'public', 'confidential'], message: 'The type must be "device", "public" or "confidential".')]
        public string $type = '',

        #[Assert\Count(max: 10)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 2000)])]
        public array $redirectUris = [],

        public ?string $authMethod = null,

        public ?array $jwks = null,

        public ?string $jwksUri = null,
    ) {
    }
}
