<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use OpenApi\Spec as OA;

/** Documents the authorization decision answer; OAuthController builds it inline. */
#[OA\Schema(
    description: 'Where the web app sends the user agent after the decision.',
    type: 'object',
    properties: [
        'redirect_uri' => new OA\Schema(
            description: 'The client\'s redirect URI with code, state and iss on approval, or error=access_denied, error_description, state and iss on denial',
            type: 'string',
            format: 'uri',
            example: 'https://player.baander.app/callback?code=abc&state=xyz&iss=https%3A%2F%2Fbaander.app',
        ),
    ],
    required: ['redirect_uri'],
    component: 'AuthorizationRedirectResource',
)]
final class AuthorizationRedirectResource
{
}
