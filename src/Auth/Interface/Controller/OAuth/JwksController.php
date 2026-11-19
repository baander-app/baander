<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Port\AccessTokenSigningKeyInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/.well-known/jwks.json',
        description: 'Returns the public signing keys used by the authorization server for JWT verification.',
        summary: 'JSON Web Key Set (RFC 7517)',
        security: [],
        responses: [
            new OA\Response(
                response: '200',
                description: 'JSON Web Key Set',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'keys', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'kty', type: 'string', example: 'RSA'),
                                new OA\Property(property: 'use', type: 'string', example: 'sig'),
                                new OA\Property(property: 'alg', type: 'string', example: 'RS256'),
                                new OA\Property(property: 'kid', description: 'The key\'s RFC 7638 thumbprint; access tokens name it in their header', type: 'string'),
                                new OA\Property(property: 'n', description: 'Base64url-encoded modulus', type: 'string'),
                                new OA\Property(property: 'e', description: 'Base64url-encoded exponent', type: 'string'),
                            ],
                            type: 'object',
                        )),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/.well-known/jwks.json', name: 'jwks', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['keys' => [$this->signingKey->publicJwk()]]);
    }
}
