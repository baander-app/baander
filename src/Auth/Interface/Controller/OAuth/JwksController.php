<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
final class JwksController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly string $publicKeyPath,
    )
    {
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
                                new OA\Property(property: 'kid', type: 'string'),
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
        $jwk = $this->buildRsaJwk();

        return new JsonResponse(['keys' => [$jwk]]);
    }

    /**
     * Parse the PEM public key and extract RSA components into a JWK.
     *
     * @return array{kty: string, use: string, alg: string, n: string, e: string}
     */
    private function buildRsaJwk(): array
    {
        $pem = file_get_contents($this->publicKeyPath);

        if ($pem === false) {
            throw new RuntimeException(sprintf('Unable to read public key from "%s".', $this->publicKeyPath));
        }

        $keyDetails = openssl_pkey_get_details(openssl_pkey_get_public($pem));

        if ($keyDetails === false || !isset($keyDetails['rsa'], $keyDetails['rsa']['n'], $keyDetails['rsa']['e'])) {
            throw new RuntimeException('Failed to parse RSA public key details.');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => $this->base64UrlEncode($keyDetails['rsa']['n']),
            'e'   => $this->base64UrlEncode($keyDetails['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
