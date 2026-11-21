<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use OpenApi\Spec as OA;

#[OA\OpenApi(
    version: '3.0.0',
    security: [
        new OA\Security\Requirement(scheme: 'bearerAuth'),
    ],
)]
#[OA\Info(
    title: 'Bånder API',
    description: 'Self-hosted media server API',
    version: '2.0.0',
)]
#[OA\Security\Scheme\Http(
    description: 'DPoP-bound access token (JWT), sent as `Authorization: DPoP <token>` with a DPoP proof. First-party apps obtain it via `/api/auth/login`, `/api/auth/login/passkey` or `/api/auth/refresh`; other clients via `/api/oauth/token`.',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    component: 'bearerAuth',
)]
#[OA\Security\Scheme\OAuth2(
    description: 'OAuth 2.0 authorization code flow with mandatory S256 PKCE. Token requests need a nonce-bearing DPoP proof, and the issued tokens are DPoP-bound.',
    flows: [
        new OA\Flow\AuthorizationCode(
            authorizationUrl: '/oauth/authorize',
            tokenUrl: '/api/oauth/token',
            refreshUrl: '/api/oauth/token',
            scopes: [
                'profile' => 'Read the user\'s own profile (GET /api/auth/me): public ID, name and creation date',
                'email' => 'Read the user\'s own profile (GET /api/auth/me) with the email address and its verification date',
                'library' => 'Browse and stream the user\'s libraries, control playback on their devices, and use transcoding, favorites, listening history, recommendations and radio',
                'playlist' => 'Read, create, change, share and delete the user\'s playlists',
            ],
        ),
    ],
    component: 'oauth2AuthCode',
)]
#[OA\Parameter(
    name: 'Accept-Language',
    in: 'header',
    description: 'Read only at registration: the supported language the browser ranks highest (`en`, `da` or `th`) becomes the new user\'s email language when it differs from the server default. Response messages are in English.',
    required: false,
    schema: new OA\Schema(type: 'string', example: 'da-DK,da;q=0.9,en;q=0.5'),
    component: 'Accept-Language',
)]
final class NativeOpenApiDefinition {}
