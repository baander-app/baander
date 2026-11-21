<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use OpenApi\Spec as OA;

/** Documents authorization endpoint errors; OAuthController builds them inline. */
#[OA\Schema(
    description: 'An OAuth error (RFC 6749 section 4.1.2.1). Without redirect_uri the client or the pushed request is invalid and the user must see the error; never redirect. `invalid_request_uri` means the pushed request is unknown, expired, already used, or was pushed by another client. With redirect_uri, navigate there.',
    type: 'object',
    properties: [
        'error' => new OA\Schema(
            type: 'string',
            enum: ['invalid_request', 'invalid_client', 'invalid_request_uri', 'unauthorized_client', 'access_denied'],
        ),
        'error_description' => new OA\Schema(type: 'string'),
        'redirect_uri' => new OA\Schema(
            description: 'The client\'s redirect URI carrying error, error_description, state and iss',
            type: 'string',
            format: 'uri',
        ),
    ],
    required: ['error', 'error_description', 'redirect_uri'],
    component: 'AuthorizationErrorResource',
)]
final class AuthorizationErrorResource
{
}
