<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Query\OAuth\ListPersonalAccessClientsQuery;
use App\Auth\Interface\Request\User\CreateClientRequest;
use App\Auth\Interface\Resource\ClientResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth/clients', name: 'oauth_clients_')]
final class ClientController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/oauth/clients/',
        summary: 'List personal access clients',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of clients',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: ClientResource::class))),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $clients = $this->commandBus
            ->dispatch(new ListPersonalAccessClientsQuery($userId))
            ->last(HandledStamp::class)?->getResult();

        return $this->successResponse(ClientResource::collection(is_array($clients) ? $clients : []));
    }

    #[OA\Post(
        path: '/api/oauth/clients/',
        summary: 'Create a new personal access client',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', example: 'My App')]),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Client created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: new Model(type: ClientResource::class)),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateClientRequest $payload): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $client = $this->commandBus
            ->dispatch(new CreatePersonalAccessClientCommand($userId, $payload->name))
            ->last(HandledStamp::class)?->getResult();

        return $this->successResponse(ClientResource::from($client), Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/oauth/clients/{publicId}',
        summary: 'Revoke an OAuth client',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Client public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Client revoked',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Client revoked.'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Client not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'revoke', methods: ['DELETE'])]
    public function revoke(string $publicId): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $clientPublicId = PublicId::fromString($publicId);
        } catch (InvalidArgumentException) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        try {
            $this->commandBus->dispatch(new RevokeClientCommand($userId, $clientPublicId));
        } catch (HandlerFailedException $exception) {
            $failures = $exception->getWrappedExceptions();
            if (count($failures) === 1 && reset($failures) instanceof ClientNotFoundException) {
                return $this->notFound();
            }

            throw $exception;
        }

        return $this->successResponse([
            'message' => $this->trans('success.client_revoked', domain: 'auth'),
        ]);
    }

    private function currentUserId(): ?Uuid
    {
        $user = $this->security->getUser();

        return $user instanceof AuthenticatedUserIdentityInterface ? Uuid::fromString($user->getId()) : null;
    }
}
