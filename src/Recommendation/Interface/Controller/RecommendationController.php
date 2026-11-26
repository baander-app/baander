<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Recommendation\Application\Command\CreateRecommendationCommand;
use App\Recommendation\Application\Command\DeleteRecommendationCommand;
use App\Recommendation\Application\Command\DeleteRecommendationsBySourceCommand;
use App\Recommendation\Application\Port\RecommendationPublicIdentityInterface;
use App\Recommendation\Application\Query\GetPersonalizedRecommendationsQuery;
use App\Recommendation\Application\Query\GetRecommendationsBySourceQuery;
use App\Recommendation\Application\Query\GetRecommendationsForUserQuery;
use App\Recommendation\Application\Query\GetTargetingRecommendationsQuery;
use App\Recommendation\Application\RecommendationLimits;
use App\Recommendation\Interface\Request\CreateRecommendationRequest;
use App\Recommendation\Interface\Resource\RecommendationResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[OA\Tag(name: 'Recommendation', description: 'Recommendation endpoints')]
#[Route('/api/recommendations', name: 'recommendation_')]
final class RecommendationController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly RecommendationPublicIdentityInterface $identities,
    ) {
    }

    /**
     * Get personalized recommendations for the authenticated user.
     */
    #[OA\Operation\Get(
        path: '/api/recommendations/',
        summary: 'Get personalized recommendations for the authenticated user',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of recommendations',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: RecommendationResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'A limit outside its range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $limit = QueryParameters::integer($request->query, 'limit', 50, 1, 200);

        $recommendations = $this->bus->dispatch(new GetRecommendationsForUserQuery(
            userId: Uuid::fromString($user->getId()),
            limit: $limit,
            administrator: $this->security->isGranted('ROLE_ADMIN'),
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse($recommendations);
    }

    /**
     * Get "For You" recommendations with explanations.
     */
    #[OA\Operation\Get(
        path: '/api/recommendations/for-you',
        summary: 'Get personalized recommendations with explanation breakdown',
        description: 'Returns aggregated recommendations with one-line explanations and per-strategy scores',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of recommendations',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'target_id' => new OA\Schema(type: 'string'),
                                        'target_type' => new OA\Schema(type: 'string'),
                                        'total_score' => new OA\Schema(type: 'number'),
                                        'explanation' => new OA\Schema(type: 'string'),
                                        'strategies' => new OA\Schema(type: 'object', additionalProperties: true),
                                        'song' => new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'public_id' => new OA\Schema(type: 'string'),
                                                'title' => new OA\Schema(type: 'string'),
                                                'album_id' => new OA\Schema(type: 'string'),
                                                'track' => new OA\Schema(type: 'integer', nullable: true),
                                                'disc' => new OA\Schema(type: 'integer', nullable: true),
                                                'year' => new OA\Schema(type: 'integer', nullable: true),
                                                'length' => new OA\Schema(type: 'number', nullable: true),
                                                'available' => new OA\Schema(type: 'boolean'),
                                            ],
                                            required: ['public_id', 'title', 'album_id', 'track', 'disc', 'year', 'length', 'available'],
                                        ),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'A limit outside its range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/for-you', name: 'for_you', methods: ['GET'])]
    public function forYou(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $limit = QueryParameters::integer($request->query, 'limit', 50, 1, 100);

        $recommendations = $this->bus->dispatch(new GetPersonalizedRecommendationsQuery(
            userId: Uuid::fromString($user->getId()),
            limit: $limit,
            targetType: 'song',
            administrator: $this->security->isGranted('ROLE_ADMIN'),
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse($recommendations);
    }

    /**
     * Get recommendations for a source entity.
     */
    #[OA\Operation\Get(
        path: '/api/recommendations/source/{sourceType}/{sourceId}',
        summary: 'Get recommendations for a source entity',
        parameters: [
            new OA\Parameter(
                name: 'sourceType',
                in: 'path',
                description: 'Source entity type',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['song', 'album', 'artist', 'movie', 'video'],
                ),
            ),
            new OA\Parameter(
                name: 'sourceId',
                in: 'path',
                description: 'Source entity ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of recommendations',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 100),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: RecommendationResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'A limit outside its range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/source/{sourceType}/{sourceId}', name: 'by_source', methods: ['GET'])]
    public function bySource(string $sourceType, string $sourceId, Request $request): JsonResponse
    {
        $limit = QueryParameters::integer($request->query, 'limit', 100, 1, 100);
        $viewerId = $this->actorId();
        $administrator = $this->security->isGranted('ROLE_ADMIN');

        $recommendations = $this->bus->dispatch(new GetRecommendationsBySourceQuery(
            sourceType: $sourceType,
            sourceId: $this->identities->internalEntityId($sourceType, $sourceId, $viewerId, $administrator)->toString(),
            viewerId: $viewerId,
            administrator: $administrator,
            limit: $limit,
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse(array_map(
            fn($r) => $this->identities->project($r, $viewerId, $administrator),
            $recommendations,
        ) |> array_filter(...) |> array_values(...));
    }

    /**
     * Get recommendations targeting an entity.
     */
    #[OA\Operation\Get(
        path: '/api/recommendations/targeting/{targetType}/{targetId}',
        summary: 'Get recommendations targeting an entity',
        parameters: [
            new OA\Parameter(
                name: 'targetType',
                in: 'path',
                description: 'Target entity type',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['song', 'album', 'artist', 'movie', 'video'],
                ),
            ),
            new OA\Parameter(
                name: 'targetId',
                in: 'path',
                description: 'Target entity ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of recommendations',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 100),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: RecommendationResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'A limit outside its range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/targeting/{targetType}/{targetId}', name: 'targeting', methods: ['GET'])]
    public function targeting(string $targetType, string $targetId, Request $request): JsonResponse
    {
        $limit = QueryParameters::integer($request->query, 'limit', 100, 1, 100);
        $viewerId = $this->actorId();
        $administrator = $this->security->isGranted('ROLE_ADMIN');

        $recommendations = $this->bus->dispatch(new GetTargetingRecommendationsQuery(
            targetType: $targetType,
            targetId: $this->identities->internalEntityId($targetType, $targetId, $viewerId, $administrator)->toString(),
            viewerId: $viewerId,
            administrator: $administrator,
            limit: $limit,
        ))->last(HandledStamp::class)?->getResult() ?? [];

        return $this->successResponse(array_map(
            fn($r) => $this->identities->project($r, $viewerId, $administrator),
            $recommendations,
        ) |> array_filter(...) |> array_values(...));
    }

    /**
     * Create a recommendation.
     */
    #[OA\Operation\Post(
        path: '/api/recommendations/',
        summary: 'Create a recommendation',
        description: 'Stores a recommendation of the authenticated user. A user keeps at most ' . RecommendationLimits::MEMBER_ROWS_PER_USER . ' rows written through this route; the recommendation generator\'s rows do not count.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: CreateRecommendationRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Created',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: RecommendationResource::class),
                        ],
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
                response: '404',
                description: 'The source or target entity is not visible to the user',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The user already keeps ' . RecommendationLimits::MEMBER_ROWS_PER_USER . ' rows written through this route (reason recommendation_limit, with limit), or a row with this source, target and name (reason recommendation_exists)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    public function store(#[MapRequestPayload] CreateRecommendationRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $administrator = $this->security->isGranted('ROLE_ADMIN');
        $recommendation = $this->bus->dispatch(new CreateRecommendationCommand(
            sourceType: $payload->source_type,
            sourceId: $this->identities->internalEntityId($payload->source_type, $payload->source_id, $userId, $administrator)->toString(),
            targetType: $payload->target_type,
            targetId: $this->identities->internalEntityId($payload->target_type, $payload->target_id, $userId, $administrator)->toString(),
            score: (float) $payload->score,
            userId: $userId,
            name: $payload->name,
            position: $payload->position,
        ))->last(HandledStamp::class)?->getResult();

        // project() answers null once access to the source or target was revoked after the write;
        // the answer then names them by the public identities the member sent.
        $data = $this->identities->project($recommendation, $userId, $administrator)
            ?? $this->identities->projectAsAddressed($recommendation, $payload->source_id, $payload->target_id);

        return $this->successResponse($data, Response::HTTP_CREATED);
    }

    /**
     * Delete a recommendation by public ID.
     */
    #[OA\Operation\Delete(
        path: '/api/recommendations/{publicId}',
        summary: 'Delete a recommendation',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Recommendation public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $publicId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        try {
            $id = $this->identities->recommendationId($publicId, Uuid::fromString($user->getId()), $this->security->isGranted('ROLE_ADMIN'));
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid public identity.');
        }

        $this->bus->dispatch(new DeleteRecommendationCommand($id));

        return $this->noContent();
    }

    /**
     * Delete all recommendations for a source entity.
     */
    #[OA\Operation\Delete(
        path: '/api/recommendations/source/{sourceType}/{sourceId}',
        summary: 'Delete all recommendations for a source entity',
        parameters: [
            new OA\Parameter(
                name: 'sourceType',
                in: 'path',
                description: 'Source entity type',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['song', 'album', 'artist', 'movie', 'video'],
                ),
            ),
            new OA\Parameter(
                name: 'sourceId',
                in: 'path',
                description: 'Source entity ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/source/{sourceType}/{sourceId}', name: 'destroy_by_source', methods: ['DELETE'])]
    public function destroyBySource(string $sourceType, string $sourceId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $this->bus->dispatch(new DeleteRecommendationsBySourceCommand(
            sourceType: $sourceType,
            sourceId: $this->identities->internalEntityId($sourceType, $sourceId, $this->actorId(), $this->security->isGranted('ROLE_ADMIN'))->toString(),
            actorId: $this->security->isGranted('ROLE_ADMIN') ? null : $this->actorId(),
        ));

        return $this->noContent();
    }
    private function actorId(): Uuid
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) { throw new AccessDeniedException(); }
        return Uuid::fromString($user->getId());
    }

}
