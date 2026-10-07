<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use OpenApi\Attributes as OA;

/** Documents authorization endpoint errors; OAuthController builds them inline. */
#[OA\Schema(
    schema: 'AuthorizationErrorResource',
    description: 'An OAuth error (RFC 6749 section 4.1.2.1). Without redirect_uri the client or redirect URI is invalid and the user must see the error; never redirect. With redirect_uri, navigate there.',
    required: ['error', 'error_description'],
    properties: [
        new OA\Property(property: 'error', type: 'string', enum: ['invalid_request', 'invalid_client', 'unauthorized_client', 'unsupported_response_type', 'invalid_scope', 'access_denied']),
        new OA\Property(property: 'error_description', type: 'string'),
        new OA\Property(property: 'redirect_uri', description: 'The client\'s redirect URI carrying error, error_description, state and iss', type: 'string', format: 'uri'),
    ],
)]
final class AuthorizationErrorResource
{
}
