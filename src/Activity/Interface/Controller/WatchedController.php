<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Command\RecordVideoProgressCommand;
use App\Activity\Application\Command\SetWatchedCommand;
use App\Activity\Application\Command\VideoProgressAction;
use App\Activity\Interface\Request\RecordVideoProgressRequest;
use App\Activity\Interface\Request\SetWatchedRequest;
use App\Activity\Interface\Resource\VideoProgressResource;
use App\Activity\Interface\Resource\WatchedStateResource;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Activity', description: 'User activity tracking endpoints')]
#[Route('/api/activity', name: 'activity_')]
final readonly class WatchedController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(private Security $security, private MessageBusInterface $bus) {}

    #[OA\Operation\Post(
        path: '/api/activity/videos/{publicId}/progress',
        summary: 'Report the current member\'s video progress',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                required: true,
                description: 'Video public ID',
                schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(schema: new OA\Schema(ref: RecordVideoProgressRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Report accepted or ignored',
                content: new OA\MediaType\Json(schema: new OA\Schema(type: 'object', properties: ['data' => new OA\Schema(ref: VideoProgressResource::class)])),
            ),
            new OA\Response(
                response: 401,
                description: 'Authentication required',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 404,
                description: 'Video or title unavailable',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid progress report',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class)),
            ),
        ],
    )]
    #[Route('/videos/{publicId}/progress', name: 'video_progress', requirements: ['publicId' => PublicId::PATTERN], methods: ['POST'])]
    public function progress(#[MapRequestPayload] RecordVideoProgressRequest $payload, string $publicId): JsonResponse
    {
        $member = $this->security->getUser();
        if (!$member instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }
        $result = $this->dispatch(new RecordVideoProgressCommand(
            memberId: Uuid::fromString($member->getId()),
            videoPublicId: PublicId::fromString($publicId),
            titleKind: $payload->titleKind,
            titlePublicId: PublicId::fromString($payload->titleId),
            positionSeconds: $payload->positionSeconds,
            action: VideoProgressAction::from($payload->action),
            reportedAt: $payload->reportedTime(),
        ));

        return new JsonResponse(['data' => VideoProgressResource::from($result)], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[OA\Operation\Put(
        path: '/api/activity/watched/{kind}/{publicId}',
        summary: 'Mark owned video titles watched or unwatched',
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['episode', 'season', 'series', 'movie'])),
            new OA\Parameter(name: 'publicId', in: 'path', required: true, description: 'Episode, season, series or movie public ID', schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(schema: new OA\Schema(ref: SetWatchedRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated watched state',
                content: new OA\MediaType\Json(schema: new OA\Schema(type: 'object', properties: ['data' => new OA\Schema(ref: WatchedStateResource::class)])),
            ),
            new OA\Response(
                response: 401,
                description: 'Authentication required',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 404,
                description: 'Title unavailable',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid watched state',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class)),
            ),
        ],
    )]
    #[Route('/watched/{kind}/{publicId}', name: 'watched_update', requirements: ['kind' => 'episode|season|series|movie', 'publicId' => PublicId::PATTERN], methods: ['PUT'])]
    public function watched(#[MapRequestPayload] SetWatchedRequest $payload, string $kind, string $publicId): JsonResponse
    {
        $member = $this->security->getUser();
        if (!$member instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }
        $result = $this->dispatch(new SetWatchedCommand(
            memberId: Uuid::fromString($member->getId()),
            kind: $kind,
            publicId: PublicId::fromString($publicId),
            watched: $payload->watched,
        ));

        return new JsonResponse(['data' => WatchedStateResource::from($result)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
