<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Spec as OA;

/** Documents the authorization decision body; OAuthController reads it as OAuth parameters. */
#[OA\Schema(
    description: 'The pushed request the consent page showed, named by its client_id and request_uri, with the user\'s decision. JSON or form-encoded; any other parameter is ignored.',
    type: 'object',
    properties: [
        'decision' => new OA\Schema(
            type: 'string',
            enum: ['approve', 'deny'],
        ),
        'client_id' => new OA\Schema(type: 'string'),
        'request_uri' => new OA\Schema(
            description: 'The request URI the client received from the pushed authorization request endpoint',
            type: 'string',
        ),
    ],
    required: ['decision', 'client_id', 'request_uri'],
    component: 'AuthorizationDecisionRequest',
)]
final class AuthorizationDecisionRequest
{
}
