<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Command\PauseTranscodeSessionCommand;
use App\Transcode\Application\Command\ResumeTranscodeSessionCommand;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Command\UpdateTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Query\TranscodeSessionQueryPort;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Interface\Request\CreateTranscodeSessionRequest;
use App\Transcode\Interface\Request\UpdateTranscodeSessionRequest;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Transcode', description: 'Video transcoding management endpoints')]
#[Route('/api/transcode/sessions', name: 'transcode_session_')]
final class TranscodeSessionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly PlaybackPortInterface $playback,
    ) {
    }

    #[OA\Post(
        path: '/api/transcode/sessions/',
        summary: 'Create a new transcode session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['videoId'],
                    properties: [
                        new OA\Property(property: 'videoId', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qualityTier', type: 'string', enum: ['360p', '480p', '720p', '1080p', '1440p', '4K']),
                        new OA\Property(property: 'audioProfile', type: 'string', enum: ['mobile_mono', 'mobile_stereo', 'streaming_stereo', 'streaming_5.1', 'broadcast_stereo', 'broadcast_5.1', 'hifi_stereo', 'opus_stereo']),
                        new OA\Property(property: 'priority', type: 'string', enum: ['critical', 'high', 'normal', 'low', 'bulk']),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(ref: new Model(type: TranscodeSessionResource::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateTranscodeSessionRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $this->playback->assertAccess(Uuid::fromString($payload->videoId));

        $envelope = $this->commandBus->dispatch(new CreateTranscodeSessionCommand(
            userId: Uuid::fromString($user->getId()),
            videoId: Uuid::fromString($payload->videoId),
            qualityTier: QualityTier::fromString($payload->qualityTier),
            audioProfile: AudioProfile::fromString($payload->audioProfile),
            priority: SessionPriority::from($payload->priority),
        ));

        $stamp = $envelope->last(HandledStamp::class);
        $session = $stamp?->getResult();

        return $this->created(TranscodeSessionResource::from($session));
    }

    #[OA\Get(
        path: '/api/transcode/sessions/',
        summary: 'List active transcode sessions for the authenticated user',
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'status', type: 'string')]))])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $sessions = $this->sessionPort->findActiveByUser(Uuid::fromString($user->getId()));

        return $this->successResponse(TranscodeSessionResource::collection($sessions));
    }

    #[OA\Get(
        path: '/api/transcode/sessions/{uuid}',
        summary: 'Get a transcode session by UUID',
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'qualityTier', type: 'string')])])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $session = $this->sessionPort->findByUuid(Uuid::fromString($uuid));
        if ($session === null) {
            return $this->notFound();
        }
        $this->assertSessionOwner($session->getUserId()->toString());

        return $this->successResponse(TranscodeSessionResource::from($session));
    }

    #[OA\Patch(
        path: '/api/transcode/sessions/{uuid}/pause',
        summary: 'Pause a transcode session',
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paused', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}/pause', name: 'pause', methods: ['PATCH'])]
    public function pause(string $uuid): JsonResponse
    {
        $this->requireOwnedSession($uuid);
        $this->commandBus->dispatch(new PauseTranscodeSessionCommand(
            sessionId: Uuid::fromString($uuid),
        ));

        return $this->successResponse(['message' => 'Session paused.']);
    }

    #[OA\Patch(
        path: '/api/transcode/sessions/{uuid}/resume',
        summary: 'Resume a paused transcode session',
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Resumed', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}/resume', name: 'resume', methods: ['PATCH'])]
    public function resume(string $uuid): JsonResponse
    {
        $this->requireOwnedSession($uuid);
        $this->commandBus->dispatch(new ResumeTranscodeSessionCommand(
            sessionId: Uuid::fromString($uuid),
        ));

        return $this->successResponse(['message' => 'Session resumed.']);
    }

    #[OA\Delete(
        path: '/api/transcode/sessions/{uuid}',
        summary: 'Cancel a transcode session',
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}', name: 'cancel', methods: ['DELETE'])]
    public function cancel(string $uuid): JsonResponse
    {
        $this->requireOwnedSession($uuid);
        $this->commandBus->dispatch(new CancelTranscodeSessionCommand(
            sessionId: Uuid::fromString($uuid),
        ));

        return $this->successResponse(['message' => 'Session cancelled.']);
    }

    #[OA\Patch(
        path: '/api/transcode/sessions/{uuid}',
        summary: 'Update a transcode session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'audioProfile', type: 'string', enum: ['mobile_mono', 'mobile_stereo', 'streaming_stereo', 'streaming_5.1', 'broadcast_stereo', 'broadcast_5.1', 'hifi_stereo', 'opus_stereo']),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{uuid}', name: 'update', methods: ['PATCH'])]
    public function update(
        string $uuid,
        #[MapRequestPayload] UpdateTranscodeSessionRequest $request,
    ): JsonResponse
    {
        $session = $this->sessionPort->findByUuid(Uuid::fromString($uuid));
        if ($session === null) {
            return $this->notFound();
        }
        $this->assertSessionOwner($session->getUserId()->toString());

        if ($request->audioProfile !== null) {
            $session->updateAudioProfile(AudioProfile::fromString($request->audioProfile));
        }

        $this->sessionPort->save($session);
        return $this->successResponse(['status' => 'ok']);
    }

    #[OA\Get(
        path: '/api/transcode/sessions/list',
        summary: 'List all transcode sessions for the authenticated user',
        responses: [
            new OA\Response(response: '200', description: 'Success'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listSessions(TranscodeSessionQueryPort $queryPort): JsonResponse
    {
        $user = $this->security->getUser();
        $sessions = $queryPort->findByUser(Uuid::fromString($user->getId()));
        return $this->successResponse(array_map(fn($d) => $d->toArray(), $sessions));
    }

    #[OA\Post(
        path: '/api/transcode/sessions/{uuid}/position',
        summary: 'Update transcode session playback position (seek signal)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'position', type: 'number', format: 'float'),
                        new OA\Property(property: 'action', type: 'string', enum: ['seek', 'pause', 'resume']),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'uuid', description: 'Session UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Position updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'position', type: 'number')])),
        ],
    )]
    #[Route('/{uuid}/position', name: 'position', methods: ['POST'])]
    public function updatePosition(
        string $uuid,
        Request $request,
    ): JsonResponse
    {
        $this->requireOwnedSession($uuid);
        try {
            $payload = $request->toArray();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->errorResponse('Expected a JSON object.', 400);
        }
        $position = $payload['position'] ?? null;
        $action = $payload['action'] ?? 'seek';
        if ((!is_int($position) && !is_float($position)) || !is_finite((float) $position) || $position < 0
            || !in_array($action, ['seek', 'pause', 'resume'], true)) {
            return $this->errorResponse('Position must be a nonnegative number and action must be seek, pause, or resume.', 422);
        }
        $position = (float) $position;

        $this->commandBus->dispatch(new UpdateTranscodePositionCommand(
            sessionId: Uuid::fromString($uuid),
            position: $position,
            action: $action,
        ));

        return $this->successResponse(['status' => 'ok', 'position' => $position]);
    }
    private function requireOwnedSession(string $uuid): void
    {
        try {
            $sessionId = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Session not found.');
        }
        $session = $this->sessionPort->findByUuid($sessionId);
        if ($session === null) {
            throw new NotFoundHttpException('Session not found.');
        }
        $this->assertSessionOwner($session->getUserId()->toString());
    }

    private function assertSessionOwner(string $ownerId): void
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new HttpException(401, 'Authentication required.');
        }
        if (!$this->security->isGranted('ROLE_ADMIN') && $ownerId !== $user->getId()) {
            throw new AccessDeniedException('This session belongs to another user.');
        }
    }

}
