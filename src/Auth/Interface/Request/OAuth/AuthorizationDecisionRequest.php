<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;

/** Documents the authorization decision body; OAuthController reads it as OAuth parameters. */
#[OA\Schema(
    schema: 'AuthorizationDecisionRequest',
    description: 'The pushed request the consent page showed, named by its client_id and request_uri, with the user\'s decision. JSON or form-encoded; any other parameter is ignored.',
    required: ['decision', 'client_id', 'request_uri'],
    properties: [
        new OA\Property(property: 'decision', type: 'string', enum: ['approve', 'deny']),
        new OA\Property(property: 'client_id', type: 'string'),
        new OA\Property(property: 'request_uri', description: 'The request URI the client received from the pushed authorization request endpoint', type: 'string'),
    ],
)]
final class AuthorizationDecisionRequest
{
}
