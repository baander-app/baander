<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Publishes the authorization server's endpoints and capabilities (RFC 8414).
 */
#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
final class AuthorizationServerMetadataController
{
    use ApiResponsesTrait;

    /** The proof algorithms DpopProofValidator accepts; a unit test keeps the two lists equal. */
    public const array DPOP_SIGNING_ALGORITHMS = ['EdDSA', 'ES256', 'ES384', 'ES512', 'PS256', 'RS256', 'RS384', 'RS512'];

    /** The client assertion algorithms ClientAssertionVerifier accepts; a unit test keeps the two lists equal. */
    public const array CLIENT_ASSERTION_SIGNING_ALGORITHMS = ['EdDSA', 'ES256', 'ES384', 'ES512', 'PS256', 'RS256', 'RS384', 'RS512'];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $issuer,
        /** The web app's consent page; a browser redirect cannot carry the DPoP-bound session. */
        private readonly string $authorizationEndpoint,
        /** @var string[] */
        private readonly array $scopes,
    ) {
    }

    #[OA\Operation\Get(
        path: '/.well-known/oauth-authorization-server',
        summary: 'OAuth 2.0 Authorization Server Metadata (RFC 8414)',
        description: 'Returns the authorization server metadata document used by clients to discover endpoints and capabilities.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authorization server metadata',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'issuer' => new OA\Schema(
                                type: 'string',
                                format: 'uri',
                                example: 'https://baander.app',
                            ),
                            'authorization_endpoint' => new OA\Schema(
                                description: 'The web app consent page (APP_URL/oauth/authorize)',
                                type: 'string',
                                format: 'uri',
                            ),
                            'token_endpoint' => new OA\Schema(type: 'string', format: 'uri'),
                            'device_authorization_endpoint' => new OA\Schema(type: 'string', format: 'uri'),
                            'jwks_uri' => new OA\Schema(type: 'string', format: 'uri'),
                            'response_types_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'grant_types_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'token_endpoint_auth_methods_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'token_endpoint_auth_signing_alg_values_supported' => new OA\Schema(
                                description: 'Algorithms a private_key_jwt client assertion may be signed with',
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'code_challenge_methods_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'scopes_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'dpop_signing_alg_values_supported' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'authorization_response_iss_parameter_supported' => new OA\Schema(type: 'boolean'),
                            'pushed_authorization_request_endpoint' => new OA\Schema(
                                description: 'Where a client pushes every authorization request first (RFC 9126)',
                                type: 'string',
                                format: 'uri',
                            ),
                            'require_pushed_authorization_requests' => new OA\Schema(
                                description: 'Always true: the authorization endpoint accepts only pushed requests',
                                type: 'boolean',
                            ),
                        ],
                    ),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_authorization_server_metadata', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->buildMetadata(), headers: ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(): array
    {
        return [
            'issuer'                                => $this->issuer,
            'authorization_endpoint'                => $this->authorizationEndpoint,
            'token_endpoint'                        => $this->generateUrl('oauth_token'),
            'device_authorization_endpoint'         => $this->generateUrl('oauth_device_authorize'),
            'jwks_uri'                              => $this->generateUrl('jwks'),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => [
                'authorization_code',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'scopes_supported'                      => $this->scopes,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'],
            'token_endpoint_auth_signing_alg_values_supported' => self::CLIENT_ASSERTION_SIGNING_ALGORITHMS,
            'code_challenge_methods_supported'      => ['S256'],
            'dpop_signing_alg_values_supported'     => self::DPOP_SIGNING_ALGORITHMS,
            'authorization_response_iss_parameter_supported' => true,
            'pushed_authorization_request_endpoint' => $this->generateUrl('oauth_par'),
            'require_pushed_authorization_requests' => true,
        ];
    }

    /** Endpoint URLs are rooted at the issuer, so they match it behind a reverse proxy too. */
    private function generateUrl(string $routeName): string
    {
        return rtrim($this->issuer, '/') . $this->urlGenerator->generate($routeName, referenceType: UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
