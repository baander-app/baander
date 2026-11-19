<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\PushedAuthorizationRequestDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PushedAuthorizationRequestResource',
    description: 'A pushed authorization request (RFC 9126 section 2.2). Send the browser to the authorization endpoint with `client_id` and this `request_uri`.',
    required: ['request_uri', 'expires_in'],
    properties: [
        new OA\Property(property: 'request_uri', description: 'Single-use reference to the pushed request', type: 'string', example: 'urn:ietf:params:oauth:request_uri:6esc_11ACC5bwc014ltc14eY22c'),
        new OA\Property(property: 'expires_in', description: 'Seconds the request URI stays usable', type: 'integer', example: 300),
    ],
)]
final class PushedAuthorizationRequestResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PushedAuthorizationRequestDTO);

        return [
            'request_uri' => $source->requestUri,
            'expires_in' => $source->expiresIn,
        ];
    }
}
