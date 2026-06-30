<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Command\RecordPlayCommand;
use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Request\PlayActivityRequest;
use App\Activity\Interface\Resource\ActivityResource;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly ActivityPortInterface $activityService,
        private readonly MessageBusInterface $commandBus,
        private readonly ActivityEnrichmentService $enrichmentService,
        private readonly SongPortInterface $songPort,
        private readonly AlbumPortInterface $albumPort,
        private readonly ArtistPortInterface $artistPort,
        private readonly MoviePortInterface $moviePort,
    ) {
    }

    /**
     * Get the authenticated user's activity history (paginated).
     */
    #[OA\Get(
        path: '/api/activity/history',
        summary: "Get the authenticated user's activity history",
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Maximum number of activities to return', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'offset', description: 'Number of activities to skip', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: ActivityResource::class)))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $activities = $this->activityService->findByUser(
            Uuid::fromString($user->getId()),
            $limit,
        );

        // Manual offset slicing (findByUser returns sorted DESC from DB)
        $activities = array_slice($activities, $offset, $limit);

        $baseUrl = $request->getSchemeAndHttpHost();
        $enriched = $this->enrichmentService->enrich($activities, $baseUrl);

        return $this->successResponse($enriched);
    }

    /**
     * Record a play event for a song.
     */
    #[OA\Post(
        path: '/api/activity/play',
        summary: 'Record a play event for a song or movie',
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(
            required: [],
            properties: [
                new OA\Property(property: 'songId', description: 'Public ID of the song being played', type: 'string', nullable: true),
                new OA\Property(property: 'albumId', description: 'Public ID of the album', type: 'string', nullable: true),
                new OA\Property(property: 'artistId', description: 'Public ID of the artist', type: 'string', nullable: true),
                new OA\Property(property: 'movieId', description: 'Public ID of the movie', type: 'string', nullable: true),
                new OA\Property(property: 'platform', description: 'Playback platform (e.g. web, mobile, desktop)', type: 'string', nullable: true),
                new OA\Property(property: 'player', description: 'Player application name', type: 'string', nullable: true),
            ],
        ))),
        responses: [
            new OA\Response(response: '201', description: 'Activity recorded', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ActivityResource::class))], type: 'object',
            )),
            new OA\Response(response: '200', description: 'Existing activity updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ActivityResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/play', name: 'play', methods: ['POST'])]
    public function play(#[MapRequestPayload] PlayActivityRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        // Resolve song (optional for movie plays)
        $songUuid = null;
        if ($payload->songId !== null && $payload->songId !== '') {
            try {
                $songPublicId = PublicId::fromString($payload->songId);
            } catch (\InvalidArgumentException) {
                return $this->errorResponse($this->trans('errors.invalid_song_id_format'));
            }

            $song = $this->songPort->findByPublicId($songPublicId);
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

            $movie = $this->moviePort->findByPublicId($moviePublicId);
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
                $album = $this->albumPort->findByPublicId($albumPublicId);
                $albumUuid = $album?->getId();
            } catch (\InvalidArgumentException) {
                // Ignore invalid album ID
            }
        }

        $artistUuid = null;
        if ($payload->artistId !== null) {
            try {
                $artistPublicId = PublicId::fromString($payload->artistId);
                $artist = $this->artistPort->findByPublicId($artistPublicId);
                $artistUuid = $artist?->getId();
            } catch (\InvalidArgumentException) {
                // Ignore invalid artist ID
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

        return $this->successResponse(ActivityResource::from($activity), Response::HTTP_CREATED);
    }

    /**
     * Toggle love on an activity.
     */
    #[OA\Post(
        path: '/api/activity/love/{publicId}',
        summary: 'Toggle love on an activity',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Activity public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ActivityResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

        $activity->toggleLove();
        $this->activityService->save($activity);

        return $this->successResponse(ActivityResource::from($activity));
    }

    /**
     * Get the authenticated user's loved items.
     */
    #[OA\Get(
        path: '/api/activity/loved',
        summary: "Get the authenticated user's loved items",
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: ActivityResource::class)))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/loved', name: 'loved', methods: ['GET'])]
    public function loved(): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $activities = $this->activityService->findLoved(Uuid::fromString($user->getId()));

        return $this->successResponse(ActivityResource::collection($activities));
    }


}
