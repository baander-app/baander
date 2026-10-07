<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;

/** Documents the authorization decision body; OAuthController reads it as OAuth parameters. */
#[OA\Schema(
    schema: 'AuthorizationDecisionRequest',
    description: 'The parameters of the authorization request the consent page showed, unchanged, with the user\'s decision. JSON or form-encoded.',
    required: ['decision', 'response_type', 'client_id', 'code_challenge', 'code_challenge_method'],
    properties: [
        new OA\Property(property: 'decision', type: 'string', enum: ['approve', 'deny']),
        new OA\Property(property: 'response_type', type: 'string', enum: ['code']),
        new OA\Property(property: 'client_id', type: 'string'),
        new OA\Property(property: 'redirect_uri', type: 'string', format: 'uri'),
        new OA\Property(property: 'scope', description: 'Space-separated scopes', type: 'string'),
        new OA\Property(property: 'state', type: 'string'),
        new OA\Property(property: 'code_challenge', type: 'string'),
        new OA\Property(property: 'code_challenge_method', type: 'string', enum: ['S256']),
    ],
)]
final class AuthorizationDecisionRequest
{
}
