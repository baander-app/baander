<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use OpenApi\Attributes as OA;

/** Documents the authorization decision answer; OAuthController builds it inline. */
#[OA\Schema(
    schema: 'AuthorizationRedirectResource',
    description: 'Where the web app sends the user agent after the decision.',
    required: ['redirect_uri'],
    properties: [
        new OA\Property(
            property: 'redirect_uri',
            description: 'The client\'s redirect URI with code, state and iss on approval, or error=access_denied, error_description, state and iss on denial',
            type: 'string',
            format: 'uri',
            example: 'https://player.baander.app/callback?code=abc&state=xyz&iss=https%3A%2F%2Fbaander.app',
        ),
    ],
)]
final class AuthorizationRedirectResource
{
}
