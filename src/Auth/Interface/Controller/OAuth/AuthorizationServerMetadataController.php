<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
final class AuthorizationServerMetadataController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $issuer,
    )
    {
    }

    #[OA\Get(
        path: '/.well-known/oauth-authorization-server',
        description: 'Returns the authorization server metadata document used by clients to discover endpoints and capabilities.',
        summary: 'OAuth 2.0 Authorization Server Metadata (RFC 8414)',
        security: [],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authorization server metadata',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'issuer', type: 'string', format: 'uri', example: 'https://baander.example.com'),
                        new OA\Property(property: 'authorization_endpoint', type: 'string', format: 'uri'),
                        new OA\Property(property: 'token_endpoint', type: 'string', format: 'uri'),
                        new OA\Property(property: 'revocation_endpoint', type: 'string', format: 'uri'),
                        new OA\Property(property: 'introspection_endpoint', type: 'string', format: 'uri'),
                        new OA\Property(property: 'device_authorization_endpoint', type: 'string', format: 'uri'),
                        new OA\Property(property: 'jwks_uri', type: 'string', format: 'uri'),
                        new OA\Property(property: 'response_types_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'grant_types_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'token_endpoint_auth_methods_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'code_challenge_methods_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'dpop_signing_alg_values_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'require_pushed_authorization_requests', type: 'boolean'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_authorization_server_metadata', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->buildMetadata());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(): array
    {
        return [
            'issuer'                                => $this->issuer,
            'authorization_endpoint'                => $this->generateUrl('oauth_authorize'),
            'token_endpoint'                        => $this->generateUrl('oauth_token'),
            'revocation_endpoint'                   => $this->generateUrl('oauth_revoke'),
            'introspection_endpoint'                => $this->generateUrl('oauth_introspect'),
            'device_authorization_endpoint'         => $this->generateUrl('oauth_device_authorize'),
            'jwks_uri'                              => $this->generateUrl('jwks'),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => [
                'authorization_code',
                'client_credentials',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            'code_challenge_methods_supported'      => ['S256'],
            'dpop_signing_alg_values_supported'     => ['EdDSA'],
            'require_pushed_authorization_requests' => false,
        ];
    }

    private function generateUrl(string $routeName): string
    {
        return $this->urlGenerator->generate(
            $routeName,
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
