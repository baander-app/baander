<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\PushedAuthorizationRequestDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'A pushed authorization request (RFC 9126 section 2.2). Send the browser to the authorization endpoint with `client_id` and this `request_uri`.',
    type: 'object',
    properties: [
        'request_uri' => new OA\Schema(
            description: 'Single-use reference to the pushed request',
            type: 'string',
            example: 'urn:ietf:params:oauth:request_uri:6esc_11ACC5bwc014ltc14eY22c',
        ),
        'expires_in' => new OA\Schema(
            description: 'Seconds the request URI stays usable',
            type: 'integer',
            example: 300,
        ),
    ],
    required: ['request_uri', 'expires_in'],
    component: 'PushedAuthorizationRequestResource',
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
