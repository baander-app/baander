<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Controller;

use App\Recommendation\Application\Command\DeleteRecommendationCommand;
use App\Recommendation\Application\Command\DeleteRecommendationsBySourceCommand;
use App\Recommendation\Application\Command\SaveRecommendationCommand;
use App\Recommendation\Application\Query\GetPersonalizedRecommendationsQuery;
use App\Recommendation\Application\Query\GetRecommendationsBySourceQuery;
use App\Recommendation\Application\Query\GetRecommendationsForUserQuery;
use App\Recommendation\Application\Query\GetTargetingRecommendationsQuery;
use App\Recommendation\Interface\Resource\RecommendationResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Recommendation', description: 'Recommendation endpoints')]
#[Route('/api/recommendations', name: 'recommendation_')]
final class RecommendationController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    /**
     * Get personalized recommendations for the authenticated user.
     */
    #[OA\Get(
        path: '/api/recommendations/',
        summary: 'Get personalized recommendations for the authenticated user',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Max recommendations (max 200)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: RecommendationResource::class)))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        $recommendations = $this->bus->dispatch(new GetRecommendationsForUserQuery(
            userId: Uuid::fromString($user->getId()),
            limit: $limit,
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse($recommendations);
    }

    /**
     * Get "For You" recommendations with explanations.
     */
    #[OA\Get(
        path: '/api/recommendations/for-you',
        summary: 'Get personalized recommendations with explanation breakdown',
        description: 'Returns aggregated recommendations with one-line explanations and per-strategy scores',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Max recommendations (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'target_id', type: 'string'),
                                    new OA\Property(property: 'target_type', type: 'string'),
                                    new OA\Property(property: 'total_score', type: 'number'),
                                    new OA\Property(property: 'explanation', type: 'string'),
                                    new OA\Property(property: 'strategies', type: 'object', additionalProperties: true),
                                    new OA\Property(property: 'song', type: 'object'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/for-you', name: 'for_you', methods: ['GET'])]
    public function forYou(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        $recommendations = $this->bus->dispatch(new GetPersonalizedRecommendationsQuery(
            userId: Uuid::fromString($user->getId()),
            limit: $limit,
            targetType: 'song',
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse($recommendations);
    }

    /**
     * Get recommendations for a source entity.
     */
    #[OA\Get(
        path: '/api/recommendations/source/{sourceType}/{sourceId}',
        summary: 'Get recommendations for a source entity',
        parameters: [
            new OA\Parameter(name: 'sourceType', description: 'Source entity type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['song', 'album', 'artist', 'movie', 'video'])),
            new OA\Parameter(name: 'sourceId', description: 'Source entity ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', description: 'Max recommendations (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 100)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: RecommendationResource::class)))], type: 'object',
            )),
        ],
    )]
    #[Route('/source/{sourceType}/{sourceId}', name: 'by_source', methods: ['GET'])]
    public function bySource(string $sourceType, string $sourceId, Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 100)));

        $recommendations = $this->bus->dispatch(new GetRecommendationsBySourceQuery(
            sourceType: $sourceType,
            sourceId: $sourceId,
            limit: $limit,
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse(array_map(
            static fn($r) => RecommendationResource::from($r),
            $recommendations,
        ));
    }

    /**
     * Get recommendations targeting an entity.
     */
    #[OA\Get(
        path: '/api/recommendations/targeting/{targetType}/{targetId}',
        summary: 'Get recommendations targeting an entity',
        parameters: [
            new OA\Parameter(name: 'targetType', description: 'Target entity type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['song', 'album', 'artist', 'movie', 'video'])),
            new OA\Parameter(name: 'targetId', description: 'Target entity ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', description: 'Max recommendations (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 100)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: RecommendationResource::class)))], type: 'object',
            )),
        ],
    )]
    #[Route('/targeting/{targetType}/{targetId}', name: 'targeting', methods: ['GET'])]
    public function targeting(string $targetType, string $targetId, Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 100)));

        $recommendations = $this->bus->dispatch(new GetTargetingRecommendationsQuery(
            targetType: $targetType,
            targetId: $targetId,
            limit: $limit,
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse(array_map(
            static fn($r) => RecommendationResource::from($r),
            $recommendations,
        ));
    }

    /**
     * Create a recommendation.
     */
    #[OA\Post(
        path: '/api/recommendations/',
        summary: 'Create a recommendation',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'source_type', type: 'string', example: 'song'),
                    new OA\Property(property: 'source_id', type: 'string', example: 'song-123'),
                    new OA\Property(property: 'target_type', type: 'string', example: 'song'),
                    new OA\Property(property: 'target_id', type: 'string', example: 'song-456'),
                    new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.85),
                    new OA\Property(property: 'name', type: 'string', example: 'default'),
                    new OA\Property(property: 'position', type: 'integer', example: 1),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: RecommendationResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $payload = $this->jsonEncoder->decode((string) $request->getContent(), 'json');

        try {
            $command = new SaveRecommendationCommand(
                sourceType: \App\Recommendation\Domain\ValueObject\RecommendationType::fromString($payload['source_type']),
                sourceId: $payload['source_id'],
                targetType: \App\Recommendation\Domain\ValueObject\RecommendationType::fromString($payload['target_type']),
                targetId: $payload['target_id'],
                score: (float) $payload['score'],
                userId: Uuid::fromString($user->getId()),
                name: $payload['name'] ?? 'default',
                position: isset($payload['position']) ? (int) $payload['position'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->bus->dispatch($command);

        return $this->created(['message' => 'Recommendation created.']);
    }

    /**
     * Delete a recommendation by UUID.
     */
    #[OA\Delete(
        path: '/api/recommendations/{uuid}',
        summary: 'Delete a recommendation',
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Recommendation UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $id = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid UUID format.');
        }

        $this->bus->dispatch(new DeleteRecommendationCommand($id));

        return $this->noContent();
    }

    /**
     * Delete all recommendations for a source entity.
     */
    #[OA\Delete(
        path: '/api/recommendations/source/{sourceType}/{sourceId}',
        summary: 'Delete all recommendations for a source entity',
        parameters: [
            new OA\Parameter(name: 'sourceType', description: 'Source entity type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['song', 'album', 'artist', 'movie', 'video'])),
            new OA\Parameter(name: 'sourceId', description: 'Source entity ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/source/{sourceType}/{sourceId}', name: 'destroy_by_source', methods: ['DELETE'])]
    public function destroyBySource(string $sourceType, string $sourceId): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $this->bus->dispatch(new DeleteRecommendationsBySourceCommand(
            sourceType: $sourceType,
            sourceId: $sourceId,
        ));

        return $this->noContent();
    }
}
