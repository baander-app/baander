<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Interface\Request\Admin\AdminCreateOAuthClientRequest;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OAuth client registration for administrators.
 *
 * Administrators list clients; super administrators register device, public and
 * confidential clients, rotate a confidential client's secret and revoke clients.
 * The app:oauth:client:* console commands dispatch the same application messages.
 */
#[OA\Tag(name: 'Admin / OAuth clients', description: 'OAuth client registration for administrators')]
#[Route('/api/admin/oauth/clients', name: 'admin_oauth_clients_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOAuthClientController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/oauth/clients',
        description: 'Every client except users\' personal access clients, revoked ones included, newest first. The first-party login client is listed with type first_party and cannot be changed here.',
        summary: 'List OAuth clients',
        responses: [
            new OA\Response(response: '200', description: 'The clients', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: AdminOAuthClientResource::class)))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Not an administrator', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
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

    #[OA\Post(
        path: '/api/admin/oauth/clients',
        description: 'Registers a device client (public, device authorization grant), a public client (authorization code with PKCE) or a confidential client (authorization code with a client secret). A confidential client\'s secret is in this response only.',
        summary: 'Register an OAuth client',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: AdminCreateOAuthClientRequest::class))),
        responses: [
            new OA\Response(response: '201', description: 'Client registered', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', ref: new Model(type: AdminOAuthClientCredentialsResource::class))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Not a super administrator', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid name, type or redirect URIs; `error.details.reason` is `invalid_registration` when the registration rules reject them', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:oauth:client:create')]
    public function create(#[MapRequestPayload] AdminCreateOAuthClientRequest $payload): JsonResponse
    {
        try {
            $registered = $this->dispatch(new RegisterClientCommand(
                name: $payload->name,
                type: $payload->type,
                redirectUris: $payload->redirectUris,
            ));
        } catch (ClientManagementException $exception) {
            return $this->managementError($exception);
        }
        assert($registered instanceof RegisteredClientDTO);

        return self::noStore($this->successResponse(AdminOAuthClientCredentialsResource::from($registered), Response::HTTP_CREATED));
    }

    #[OA\Post(
        path: '/api/admin/oauth/clients/{clientId}/rotate-secret',
        description: 'Confidential clients only. The old secret stops working at once; tokens already issued stay valid. The new secret is in this response only.',
        summary: 'Rotate a confidential client\'s secret',
        parameters: [new OA\Parameter(name: 'clientId', description: 'The OAuth client_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: '200', description: 'New secret issued', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', ref: new Model(type: AdminOAuthClientCredentialsResource::class))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Not a super administrator', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Unknown client', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'The client is first-party (`error.details.reason` = `protected_client`), has no secret (`no_secret`) or is revoked (`revoked`)', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
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

        try {
            $registered = $this->dispatch(new RotateClientSecretCommand($publicId));
        } catch (ClientNotFoundException) {
            return $this->notFound('OAuth client not found.');
        } catch (ClientManagementException $exception) {
            return $this->managementError($exception);
        }
        assert($registered instanceof RegisteredClientDTO);

        return self::noStore($this->successResponse(AdminOAuthClientCredentialsResource::from($registered)));
    }

    #[OA\Post(
        path: '/api/admin/oauth/clients/{clientId}/revoke',
        description: 'Revokes the client together with every access and refresh token issued to it. Revoking a revoked client succeeds again.',
        summary: 'Revoke an OAuth client',
        parameters: [new OA\Parameter(name: 'clientId', description: 'The OAuth client_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: '200', description: 'Client revoked', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', ref: new Model(type: AdminOAuthClientResource::class))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Not a super administrator', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Unknown client', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'The client is first-party (`error.details.reason` = `protected_client`)', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
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

        try {
            $client = $this->dispatch(new RevokeRegisteredClientCommand($publicId));
        } catch (ClientNotFoundException) {
            return $this->notFound('OAuth client not found.');
        } catch (ClientManagementException $exception) {
            return $this->managementError($exception);
        }

        return $this->successResponse(AdminOAuthClientResource::from($client));
    }

    /**
     * Dispatches synchronously and rethrows the client management errors the handlers raise.
     */
    private function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ClientNotFoundException || $wrapped instanceof ClientManagementException) {
                    throw $wrapped;
                }
            }

            throw $exception;
        }
    }

    private function managementError(ClientManagementException $exception): JsonResponse
    {
        $status = $exception->reason === ClientManagementException::INVALID_REGISTRATION
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_CONFLICT;

        return $this->errorResponse($exception->getMessage(), $status, ['reason' => $exception->reason]);
    }

    private static function publicId(string $value): ?PublicId
    {
        try {
            return PublicId::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** A response carrying a client secret must not be cached (RFC 6749 section 5.1 applies the same rule to tokens). */
    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
