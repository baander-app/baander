<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface as DomainAccessTokenRepository;
use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Token revocation and introspection for first-party tokens.
 *
 * Tokens are issued only by password login, passkey login and refresh under /api/auth.
 */
#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth', name: 'oauth_')]
final class OAuthController
{
    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly DomainAccessTokenRepository $domainAccessTokenRepository,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly string $resourceServerUri,
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

    #[OA\Post(
        path: '/api/oauth/introspect',
        summary: 'Introspect an access token (RFC 7662)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'token', description: 'The access token to introspect', type: 'string'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Token introspection result',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'active', type: 'boolean'),
                        new OA\Property(property: 'scope', type: 'string', nullable: true),
                        new OA\Property(property: 'exp', description: 'Expiration timestamp', type: 'integer', nullable: true),
                        new OA\Property(property: 'client_id', type: 'string', nullable: true),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Invalid or expired token', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/introspect', name: 'introspect', methods: ['POST'])]
    public function introspect(Request $request): JsonResponse
    {
        $psrRequest = $this->psrHttpFactory->createRequest($request);

        try {
            $psrRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            $tokenId = $psrRequest->getAttribute('oauth_user_id');
        } catch (OAuthServerException) {
            return new JsonResponse(['active' => false]);
        } catch (\Throwable) {
            return new JsonResponse(['active' => false]);
        }

        // The resource server validates the token but doesn't expose scope/expiry
        // directly. Use the domain repo for a full introspection response.
        $domain = $this->findDomainAccessToken($tokenId);

        if ($domain === null || $domain->isRevoked() || $domain->isExpired()) {
            return new JsonResponse(['active' => false]);
        }

        return new JsonResponse([
            'active' => true,
            'scope' => implode(' ', $domain->getScopeIdentifiers()),
            'exp' => $domain->getExpiresAt()?->getTimestamp(),
            'client_id' => $domain->getClient()->getPublicId()->toString(),
            'aud' => $this->resourceServerUri,
            'token_type' => 'Bearer',
        ]);
    }

    // --- Internal ---

    private function findDomainAccessToken(?string $tokenId): ?AccessToken
    {
        if ($tokenId === null || trim($tokenId) === '') {
            return null;
        }

        try {
            return $this->domainAccessTokenRepository->findByTokenId(
                TokenId::fromString($tokenId),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
