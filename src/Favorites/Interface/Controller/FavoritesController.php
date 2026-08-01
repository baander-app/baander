<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Controller;

use App\Favorites\Application\Command\AddFavoriteCommand;
use App\Favorites\Application\Command\RemoveFavoriteCommand;
use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Interface\Request\AddFavoriteRequest;
use App\Favorites\Interface\Resource\FavoriteResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Favorites', description: 'User favorites endpoints')]
#[Route('/api/favorites', name: 'favorites_')]
final class FavoritesController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly FavoritesPortInterface $favoritesPort,
    ) {
    }

    #[OA\Get(
        path: '/api/favorites/',
        summary: 'List user favorites',
        parameters: [
            new OA\Parameter(name: 'entityType', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['song', 'album', 'artist'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: \App\Favorites\Interface\Resource\FavoriteResource::class))),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'current_page', type: 'integer'),
                    new OA\Property(property: 'last_page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                ], type: 'object'),
            ])),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $entityType = $request->query->get('entityType');
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $limit;

        $type = $entityType !== null ? FavoriteType::tryFrom($entityType) : null;
        $favorites = $this->favoritesPort->findByUser($userId, $type, $limit, $offset);
        $total = $this->favoritesPort->countByUser($userId, $type);

        return $this->paginatedResponse(new PaginatedResponse(
            data: FavoriteResource::collection($favorites),
            currentPage: $page,
            lastPage: (int) ceil($total / $limit) ?: 1,
            perPage: $limit,
            total: $total,
        ));
    }

    #[OA\Post(
        path: '/api/favorites/',
        summary: 'Add a favorite',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['entityType', 'entityPublicId'],
                    properties: [
                        new OA\Property(property: 'entityType', type: 'string', enum: ['song', 'album', 'artist']),
                        new OA\Property(property: 'entityPublicId', type: 'string'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: \App\Favorites\Interface\Resource\FavoriteResource::class))])),
        ],
    )]
    #[Route('/', name: 'add', methods: ['POST'])]
    public function add(#[MapRequestPayload] AddFavoriteRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $envelope = $this->commandBus->dispatch(new AddFavoriteCommand(
            userId: Uuid::fromString($user->getId()),
            entityType: $payload->entityType,
            entityPublicId: $payload->entityPublicId,
        ));
        $favorite = $envelope->last(HandledStamp::class)?->getResult();

        return $this->created(FavoriteResource::from($favorite));
    }

    #[OA\Delete(
        path: '/api/favorites/{publicId}',
        summary: 'Remove a favorite',
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Removed', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
        ],
    )]
    #[Route('/{publicId}', name: 'remove', methods: ['DELETE'])]
    public function remove(string $publicId): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $this->commandBus->dispatch(new RemoveFavoriteCommand(
            userId: Uuid::fromString($user->getId()),
            publicId: PublicId::fromString($publicId),
        ));

        return $this->successResponse(['message' => 'Favorite removed.']);
    }
}
