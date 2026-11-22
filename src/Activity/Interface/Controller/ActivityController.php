<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Command\RecordPlayCommand;
use App\Activity\Application\Command\ToggleLoveCommand;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Request\PlayActivityRequest;
use App\Activity\Interface\Resource\ActivityResource;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
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

#[OA\Tag(name: 'Activity', description: 'User activity tracking endpoints')]
#[Route('/api/activity', name: 'activity_')]
final class ActivityController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly ActivityPortInterface $activityService,
        private readonly MessageBusInterface $commandBus,
        private readonly ActivityEnrichmentService $enrichmentService,
        private readonly SongPortInterface $songPort,
        private readonly AlbumPortInterface $albumPort,
        private readonly ArtistPortInterface $artistPort,
        private readonly MoviePortInterface $moviePort,
        private readonly \App\Activity\Application\Port\ActivityIdentityProjectionPortInterface $identities,
        private readonly PublicOrigins $publicOrigins,
    ) {
    }

    /**
     * Get the authenticated user's activity history (paginated).
     */
    #[OA\Operation\Get(
        path: '/api/activity/history',
        summary: "Get the authenticated user's activity history",
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of activities to return',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 50,
                ),
            ),
            new OA\Parameter(
                name: 'offset',
                in: 'query',
                description: 'Number of activities to skip',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                    default: 0,
                ),
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
                                items: new OA\Schema(ref: ActivityResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid query parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $pagination = QueryParameters::pagination($request->query);

        $activities = $this->activityService->findByUser(
            Uuid::fromString($user->getId()),
            $pagination->limit,
            $pagination->offset,
        );

        $enriched = $this->enrichmentService->enrich($activities, $this->publicOrigins->forRequest($request));

        return $this->successResponse($enriched);
    }

    /**
     * Record a play event for a song.
     */
    #[OA\Operation\Post(
        path: '/api/activity/play',
        summary: 'Record a play event for a song or movie',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'songId' => new OA\Schema(
                            description: 'Public ID of the song being played',
                            type: 'string',
                            nullable: true,
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'albumId' => new OA\Schema(
                            description: 'Public ID of the album',
                            type: 'string',
                            nullable: true,
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'artistId' => new OA\Schema(
                            description: 'Public ID of the artist',
                            type: 'string',
                            nullable: true,
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'movieId' => new OA\Schema(
                            description: 'Public ID of the movie',
                            type: 'string',
                            nullable: true,
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'platform' => new OA\Schema(
                            description: 'Playback platform (e.g. web, mobile, desktop)',
                            type: 'string',
                            nullable: true,
                        ),
                        'player' => new OA\Schema(
                            description: 'Player application name',
                            type: 'string',
                            nullable: true,
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Activity recorded',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ActivityResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Existing activity updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ActivityResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/play', name: 'play', methods: ['POST'])]
    public function play(#[MapRequestPayload] PlayActivityRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $scope = $this->identities->readScope(Uuid::fromString($user->getId()), $this->security->isGranted('ROLE_ADMIN'));

        // Resolve song (optional for movie plays)
        $songUuid = null;
        if ($payload->songId !== null && $payload->songId !== '') {
            try {
                $songPublicId = PublicId::fromString($payload->songId);
            } catch (\InvalidArgumentException) {
                return $this->errorResponse($this->trans('errors.invalid_song_id_format'));
            }

            $song = $this->songPort->findVisibleByPublicId($songPublicId, $scope);
            if ($song === null) {
                return $this->notFound($this->trans('errors.not_found', domain: 'song'));
            }
            $songUuid = $song->getId();
        }

        // Resolve movie (optional, required if no song)
        $movieUuid = null;
        if ($payload->movieId !== null && $payload->movieId !== '') {
            try {
                $moviePublicId = PublicId::fromString($payload->movieId);
            } catch (\InvalidArgumentException) {
                return $this->errorResponse($this->trans('errors.invalid_movie_id_format'));
            }

            $movie = $this->moviePort->findVisibleByPublicId($moviePublicId, $scope);
            if ($movie === null) {
                return $this->notFound($this->trans('errors.not_found', domain: 'movie'));
            }
            $movieUuid = $movie->getId();
        }

        // At least one media reference required
        if ($songUuid === null && $movieUuid === null) {
            return $this->errorResponse('Either songId or movieId must be provided.');
        }

        $albumUuid = null;
        if ($payload->albumId !== null) {
            try {
                $albumPublicId = PublicId::fromString($payload->albumId);
                $album = $this->albumPort->findVisibleByPublicId($albumPublicId, $scope);
                if ($album === null) {
                    return $this->notFound('The album was not found.');
                }
                $albumUuid = $album->getId();
            } catch (\InvalidArgumentException) {
                return $this->errorResponse('Invalid album public ID.');
            }
        }

        $artistUuid = null;
        if ($payload->artistId !== null) {
            try {
                $artistPublicId = PublicId::fromString($payload->artistId);
                $artist = $this->artistPort->findVisibleByPublicId($artistPublicId, $scope);
                if ($artist === null) {
                    return $this->notFound('The artist was not found.');
                }
                $artistUuid = $artist->getId();
            } catch (\InvalidArgumentException) {
                return $this->errorResponse('Invalid artist public ID.');
            }
        }

        $envelope = $this->commandBus->dispatch(new RecordPlayCommand(
            userId: Uuid::fromString($user->getId()),
            songId: $songUuid,
            albumId: $albumUuid,
            artistId: $artistUuid,
            movieId: $movieUuid,
            platform: $payload->platform,
            player: $payload->player,
        ));

        $activity = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse($this->enrichmentService->enrich([$activity], '')[0], Response::HTTP_CREATED);
    }

    /**
     * Toggle love on an activity.
     */
    #[OA\Operation\Post(
        path: '/api/activity/love/{publicId}',
        summary: 'Toggle love on an activity',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Activity public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
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
                            'data' => new OA\Schema(ref: ActivityResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/love/{publicId}', name: 'love', methods: ['POST'])]
    public function love(string $publicId): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $activity = $this->activityService->findByPublicId($resolvedPublicId);

        if ($activity === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'activity'));
        }

        if (!$user instanceof AuthenticatedUserIdentityInterface || !$activity->getUserId()->equals(Uuid::fromString($user->getId()))) {
            return $this->forbidden();
        }

        // Dispatch through the command bus rather than mutating the aggregate
        // directly — ToggleLoveHandler owns the toggle + persistence.
        $envelope = $this->commandBus->dispatch(new ToggleLoveCommand(
            activityId: $activity->getId(),
        ));

        $activity = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse($this->enrichmentService->enrich([$activity], '')[0]);
    }

    /**
     * Get the authenticated user's loved items.
     */
    #[OA\Operation\Get(
        path: '/api/activity/loved',
        summary: "Get the authenticated user's loved items",
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
                                items: new OA\Schema(ref: ActivityResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/loved', name: 'loved', methods: ['GET'])]
    public function loved(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $activities = $this->activityService->findLoved(Uuid::fromString($user->getId()));

        return $this->successResponse($this->enrichmentService->enrich($activities, ''));
    }


}
