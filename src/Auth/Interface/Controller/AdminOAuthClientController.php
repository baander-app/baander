<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\ReplaceClientKeysCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Interface\Request\Admin\AdminCreateOAuthClientRequest;
use App\Auth\Interface\Request\Admin\AdminReplaceOAuthClientKeysRequest;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use InvalidArgumentException;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OAuth client registration for administrators.
 *
 * Administrators list clients; super administrators register device, public and
 * confidential clients, rotate a secret client's secret, replace a key client's
 * keys and revoke clients. The app:oauth:client:* console commands dispatch the
 * same application messages, and the handlers' outcome exceptions decide the status.
 */
#[OA\Tag(
    name: 'Admin / OAuth clients',
    description: 'OAuth client registration for administrators',
)]
#[Route('/api/admin/oauth/clients', name: 'admin_oauth_clients_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOAuthClientController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/oauth/clients',
        summary: 'List OAuth clients',
        description: 'Every client except users\' personal access clients, revoked ones included, newest first. The first-party login client is listed with type first_party and cannot be changed here.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'The clients',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: AdminOAuthClientResource::class),
                            ),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Not an administrator',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    #[CliCounterpart('app:oauth:client:list')]
    public function list(): JsonResponse
    {
        $clients = $this->dispatch(new ListRegisteredClientsQuery());
        assert(is_array($clients));

        return $this->successResponse(AdminOAuthClientResource::collection($clients));
    }

    #[OA\Operation\Post(
        path: '/api/admin/oauth/clients',
        summary: 'Register an OAuth client',
        description: 'Registers a device client (public, device authorization grant), a public client (authorization code with PKCE) or a confidential client (authorization code with client authentication). A confidential client authenticates with a generated secret (client_secret_post, the default), which is in this response only, or with its own signing keys (private_key_jwt), given as exactly one of an inline JWK Set and a jwks_uri.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: AdminCreateOAuthClientRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Client registered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: AdminOAuthClientCredentialsResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Not a super administrator',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid name, type, redirect URIs, authentication method or keys; `error.details.reason` is `invalid_registration` when the registration rules reject them',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:oauth:client:create')]
    public function create(#[MapRequestPayload] AdminCreateOAuthClientRequest $payload): JsonResponse
    {
        $registered = $this->dispatch(new RegisterClientCommand(
            name: $payload->name,
            type: $payload->type,
            redirectUris: $payload->redirectUris,
            authMethod: $payload->authMethod,
            jwks: $payload->jwks,
            jwksUri: $payload->jwksUri,
        ));
        assert($registered instanceof RegisteredClientDTO);

        return self::noStore($this->successResponse(AdminOAuthClientCredentialsResource::from($registered), Response::HTTP_CREATED));
    }

    #[OA\Operation\Post(
        path: '/api/admin/oauth/clients/{clientId}/rotate-secret',
        summary: 'Rotate a client\'s secret',
        description: 'Clients that authenticate with client_secret_post only. The old secret stops working at once; tokens already issued stay valid. The new secret is in this response only.',
        parameters: [
            new OA\Parameter(
                name: 'clientId',
                in: 'path',
                description: 'The OAuth client_id',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'New secret issued',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: AdminOAuthClientCredentialsResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Not a super administrator',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Unknown client',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The client is first-party (`error.details.reason` = `protected_client`), has no secret (`no_secret`) or is revoked (`revoked`)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{clientId}/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:oauth:client:rotate-secret')]
    public function rotateSecret(string $clientId): JsonResponse
    {
        $publicId = self::publicId($clientId);
        if ($publicId === null) {
            return $this->notFound('OAuth client not found.');
        }

        $registered = $this->dispatch(new RotateClientSecretCommand($publicId));
        assert($registered instanceof RegisteredClientDTO);

        return self::noStore($this->successResponse(AdminOAuthClientCredentialsResource::from($registered)));
    }

    #[OA\Operation\Post(
        path: '/api/admin/oauth/clients/{clientId}/keys',
        summary: 'Replace a client\'s public keys',
        description: 'Clients that authenticate with private_key_jwt only. Replaces the inline JWK Set or the jwks_uri; the new source may be of the other kind. Assertions signed with the old keys stop working at once; tokens already issued stay valid.',
        parameters: [
            new OA\Parameter(
                name: 'clientId',
                in: 'path',
                description: 'The OAuth client_id',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: AdminReplaceOAuthClientKeysRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Keys replaced',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: AdminOAuthClientResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Not a super administrator',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Unknown client',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The client is first-party (`error.details.reason` = `protected_client`), does not authenticate with keys (`no_keys`) or is revoked (`revoked`)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Not exactly one key source, or keys the rules reject; `error.details.reason` is `invalid_keys`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{clientId}/keys', name: 'replace_keys', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:oauth:client:replace-keys')]
    public function replaceKeys(string $clientId, #[MapRequestPayload] AdminReplaceOAuthClientKeysRequest $payload): JsonResponse
    {
        $publicId = self::publicId($clientId);
        if ($publicId === null) {
            return $this->notFound('OAuth client not found.');
        }

        $client = $this->dispatch(new ReplaceClientKeysCommand($publicId, $payload->jwks, $payload->jwksUri));

        return self::noStore($this->successResponse(AdminOAuthClientResource::from($client)));
    }

    #[OA\Operation\Post(
        path: '/api/admin/oauth/clients/{clientId}/revoke',
        summary: 'Revoke an OAuth client',
        description: 'Revokes the client together with every access and refresh token issued to it. Revoking a revoked client succeeds again.',
        parameters: [
            new OA\Parameter(
                name: 'clientId',
                in: 'path',
                description: 'The OAuth client_id',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Client revoked',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: AdminOAuthClientResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Not a super administrator',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Unknown client',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The client is first-party (`error.details.reason` = `protected_client`)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{clientId}/revoke', name: 'revoke', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:oauth:client:revoke')]
    public function revoke(string $clientId): JsonResponse
    {
        $publicId = self::publicId($clientId);
        if ($publicId === null) {
            return $this->notFound('OAuth client not found.');
        }

        $client = $this->dispatch(new RevokeRegisteredClientCommand($publicId));

        return $this->successResponse(AdminOAuthClientResource::from($client));
    }

    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    private static function publicId(string $value): ?PublicId
    {
        try {
            return PublicId::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A response carrying a client secret must not be cached (RFC 6749 section 5.1 applies
     * the same rule to tokens); a key replacement's answer follows the same rule.
     */
    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
