<?php

declare(strict_types=1);

namespace App\Session\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Application\Command\ClaimSessionCommand;
use App\Session\Application\Command\CreateSessionCommand;
use App\Session\Application\Command\SyncSessionCommand;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Interface\Request\ClaimSessionRequest;
use App\Session\Interface\Request\CreateSessionRequest;
use App\Session\Interface\Request\SyncSessionRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
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

#[OA\Tag(name: 'Session', description: 'Listening session management')]
#[Route('/api/session', name: 'session_')]
final class SessionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly SessionPortInterface $sessionPort,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/session',
        summary: 'Get the current listening session',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Current session data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'activeDeviceId', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'currentTrackIndex', type: 'integer'),
                            new OA\Property(property: 'position', type: 'number'),
                            new OA\Property(property: 'playbackState', type: 'string'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $session = $this->sessionPort->getSession($userId);

        if ($session === null) {
            return new JsonResponse(['data' => null], Response::HTTP_OK);
        }

        return $this->successResponse($session);
    }

    #[OA\Put(
        path: '/api/session',
        summary: 'Sync playback state (position, queue, playback state)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['queue', 'currentTrackIndex', 'position', 'playbackState'],
                properties: [
                    new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0),
                    new OA\Property(property: 'position', type: 'number', minimum: 0),
                    new OA\Property(property: 'playbackState', type: 'string', enum: ['playing', 'paused', 'stopped']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Session synced',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'activeDeviceId', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'currentTrackIndex', type: 'integer'),
                            new OA\Property(property: 'position', type: 'number'),
                            new OA\Property(property: 'playbackState', type: 'string'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error or missing X-Device-Id header', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'sync', methods: ['PUT'])]
    public function sync(Request $request, #[MapRequestPayload] SyncSessionRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $deviceIdString = $request->headers->get('X-Device-Id');
        if ($deviceIdString === null || $deviceIdString === '') {
            return $this->errorResponse('X-Device-Id header is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $deviceId = Uuid::fromString($deviceIdString);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid X-Device-Id header.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = Uuid::fromString($user->getId());

        $envelope = $this->commandBus->dispatch(new SyncSessionCommand(
            userId: $userId,
            deviceId: $deviceId,
            queue: $payload->queue,
            currentTrackIndex: $payload->currentTrackIndex,
            position: $payload->position,
            playbackState: $payload->playbackState,
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/session/claim',
        summary: 'Claim the session for a specific device',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['deviceId'],
                properties: [
                    new OA\Property(property: 'deviceId', type: 'string', format: 'uuid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Session claimed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'activeDeviceId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'currentTrackIndex', type: 'integer'),
                            new OA\Property(property: 'position', type: 'number'),
                            new OA\Property(property: 'playbackState', type: 'string'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/claim', name: 'claim', methods: ['POST'])]
    public function claim(#[MapRequestPayload] ClaimSessionRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $deviceId = Uuid::fromString($payload->deviceId);

        $envelope = $this->commandBus->dispatch(new ClaimSessionCommand(
            userId: $userId,
            deviceId: $deviceId,
            queue: $payload->queue,
            currentTrackIndex: $payload->currentTrackIndex,
            position: $payload->position,
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/session/new',
        summary: 'Start a new listening session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['queue', 'currentTrackIndex', 'position'],
                properties: [
                    new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0),
                    new OA\Property(property: 'position', type: 'number', minimum: 0),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Session created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'activeDeviceId', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'currentTrackIndex', type: 'integer'),
                            new OA\Property(property: 'position', type: 'number'),
                            new OA\Property(property: 'playbackState', type: 'string'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/new', name: 'new', methods: ['POST'])]
    public function new(#[MapRequestPayload] CreateSessionRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        $envelope = $this->commandBus->dispatch(new CreateSessionCommand(
            userId: $userId,
            queue: $payload->queue,
            currentTrackIndex: $payload->currentTrackIndex,
            position: $payload->position,
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result, Response::HTTP_CREATED);
    }

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }
}
