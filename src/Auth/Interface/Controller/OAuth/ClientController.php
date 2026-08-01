<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\User\CreateClientRequest;
use App\Auth\Interface\Resource\ClientResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth/clients', name: 'oauth_clients_')]
final class ClientController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly ClientRepositoryInterface $clientRepository,
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
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $clients = $this->clientRepository->findPersonalAccessClientsByUser($userId);

        return $this->successResponse(ClientResource::collection($clients));
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
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateClientRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $client = Client::createPersonalAccess($payload->name, $userId);

        $this->clientRepository->saveClient($client);

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
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Client not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'revoke', methods: ['DELETE'])]
    public function revoke(string $publicId): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $client = $this->clientRepository->findClientByPublicId($resolvedPublicId);

        if ($client === null) {
            return $this->notFound();
        }

        $userId = Uuid::fromString($user->getId());
        if (!$client->isOwnedBy($userId)) {
            return $this->notFound();
        }

        $client->revoke();
        $this->clientRepository->saveClient($client);

        return $this->successResponse([
            'message' => $this->trans('success.client_revoked', domain: 'auth'),
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }
}
