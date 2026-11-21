<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Port\AccessTokenSigningKeyInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
final class JwksController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AccessTokenSigningKeyInterface $signingKey,
    ) {
    }

    #[OA\Operation\Get(
        path: '/.well-known/jwks.json',
        summary: 'JSON Web Key Set (RFC 7517)',
        description: 'Returns the public signing keys used by the authorization server for JWT verification.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'JSON Web Key Set',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'keys' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'kty' => new OA\Schema(type: 'string', example: 'RSA'),
                                        'use' => new OA\Schema(type: 'string', example: 'sig'),
                                        'alg' => new OA\Schema(type: 'string', example: 'RS256'),
                                        'kid' => new OA\Schema(
                                            description: 'The key\'s RFC 7638 thumbprint; access tokens name it in their header',
                                            type: 'string',
                                        ),
                                        'n' => new OA\Schema(description: 'Base64url-encoded modulus', type: 'string'),
                                        'e' => new OA\Schema(description: 'Base64url-encoded exponent', type: 'string'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/.well-known/jwks.json', name: 'jwks', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['keys' => [$this->signingKey->publicJwk()]]);
    }
}
