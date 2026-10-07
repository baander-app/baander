<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Token revocation for first-party tokens.
 *
 * Tokens are issued only by password login, passkey login and refresh under /api/auth.
 */
#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth', name: 'oauth_')]
final class OAuthController
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
    ) {
    }

    #[OA\Post(
        path: '/api/oauth/revoke',
        summary: 'Revoke an access or refresh token (RFC 7009)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['token'], properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'access-token-to-revoke'),
                    new OA\Property(property: 'tokenTypeHint', description: 'Hint for the token type (e.g. "access_token" or "refresh_token")', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Token revoked (always 200, even for invalid tokens per RFC 7009)', content: new OA\JsonContent()),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(#[MapRequestPayload] RevokeTokenRequest $payload): JsonResponse
    {
        try {
            if ($payload->tokenTypeHint === 'refresh_token') {
                $this->refreshTokenRepository->revokeRefreshToken($payload->token);
            } else {
                $this->accessTokenRepository->revokeAccessToken($payload->token);
            }
        } catch (\Throwable) {
            // Per RFC 7009, the revocation endpoint MUST return 200
            // even if the token is invalid — prevents token probing.
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }
}
