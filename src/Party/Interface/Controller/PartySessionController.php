<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\Command\EndPartySessionCommand;
use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Interface\Request\CreatePartySessionRequest;
use App\Party\Interface\Request\SyncPlaybackRequest;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Party\Interface\Resource\PartySessionResource;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Party', description: 'Watch party session endpoints')]
#[Route('/api/party/sessions', name: 'party_session_')]
final class PartySessionController
{
    use ApiResponsesTrait;
    use PartyMembershipTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyPublicIdentityPortInterface $identities,
        private readonly PartyMemberPortInterface $memberPort,
    ) {
    }

    /**
     * Unwrap the handler result from a command-bus Envelope.
     */
    private function unwrapResult(Envelope $envelope): mixed
    {
        $stamp = $envelope->last(HandledStamp::class);

        return $stamp?->getResult();
    }

    #[OA\Operation\Post(
        path: '/api/party/sessions/',
        summary: 'Create a new watch party session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(ref: CreatePartySessionRequest::class),
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
                            'data' => new OA\Schema(ref: PartySessionResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The video or transcode job does not exist, another user owns the job, or the user may not play its video',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, including a transcode job of another video',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreatePartySessionRequest $payload, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        try {
            $envelope = $this->commandBus->dispatch(new CreatePartySessionCommand(
                hostUserId: Uuid::fromString($user->getId()),
                videoId: $this->identities->videoId(PublicId::fromString($payload->videoId)),
                transcodeJobId: $payload->transcodeJobId === null ? null : $this->identities->transcodeJobId(PublicId::fromString($payload->transcodeJobId)),
                maxMembers: $payload->maxMembers,
            ));
        } catch (PartyMediaNotFoundException $exception) {
            return $this->notFound($exception->getMessage());
        } catch (HandlerFailedException $exception) {
            $failures = $exception->getWrappedExceptions();
            $failure = count($failures) === 1 ? reset($failures) : null;
            if ($failure instanceof PartyMediaNotFoundException) {
                return $this->notFound($failure->getMessage());
            }
            if ($failure instanceof TranscodeJobVideoMismatchException) {
                return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, [
                    'transcodeJobId' => [$failure->getMessage()],
                ]);
            }

            throw $exception;
        }

        return $this->created(['data' => PartySessionResource::from($this->identities->session($this->unwrapResult($envelope)))]);
    }

    #[OA\Operation\Get(
        path: '/api/party/sessions/',
        summary: 'List the caller\'s active party sessions',
        description: 'Lists only the active parties the caller hosts or has joined. A party\'s host invites the users who may join it; an invited user finds the party here once they have joined.',
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
                                        'publicId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                    ],
                                ),
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
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $sessions = $this->sessionPort->findActiveSessionsForMember(Uuid::fromString($user->getId()));

        return $this->successResponse(PartySessionResource::collection(array_map($this->identities->session(...), $sessions)));
    }

    #[OA\Operation\Get(
        path: '/api/party/sessions/{publicId}',
        summary: 'Get a party session',
        description: 'Returns the session and its members to the session\'s host and members only. Live updates arrive over the application WebSocket: request a single-use ticket from `POST /api/ws/tickets`, upgrade at `/api/ws`, then send `party.join` with the session public ID as `sessionId` to enter its room.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
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
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                    'members' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'publicId' => new OA\Schema(
                                                    type: 'string',
                                                    minLength: PublicId::LENGTH,
                                                    maxLength: PublicId::LENGTH,
                                                    pattern: PublicId::SCHEMA_PATTERN,
                                                ),
                                            ],
                                        ),
                                    ),
                                ],
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
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $members = $this->memberPort->findBySession($session->getId());

        return $this->successResponse(array_merge(
            PartySessionResource::from($this->identities->session($session)),
            ['members' => PartyMemberResource::collection(array_map($this->identities->member(...), $members))],
        ));
    }

    #[OA\Operation\Post(
        path: '/api/party/sessions/{publicId}/join',
        summary: 'Join a party session',
        description: 'Only a member of the session and a user its host has invited may join. A member who joins again keeps their place, also when the session is full. Every other caller gets the answer an unknown session gets.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
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
                description: 'Joined',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                ],
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
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither a member of it nor invited to it',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The session has ended, or it is full and the caller is not a member of it yet',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/join', name: 'join', methods: ['POST'])]
    public function join(string $publicId, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null) {
            return $this->notFound();
        }

        $member = $this->unwrapResult($this->commandBus->dispatch(new JoinPartySessionCommand(
            userId: Uuid::fromString($user->getId()),
            sessionId: $session->getId(),
        )));

        return $this->successResponse(PartyMemberResource::from($this->identities->member($member)));
    }

    #[OA\Operation\Post(
        path: '/api/party/sessions/{publicId}/leave',
        summary: 'Leave a party session',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
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
                description: 'Left',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/leave', name: 'leave', methods: ['POST'])]
    public function leave(string $publicId, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $this->commandBus->dispatch(new LeavePartySessionCommand(
            userId: Uuid::fromString($user->getId()),
            sessionId: $session->getId(),
        ));

        return $this->successResponse(['message' => 'Left session.']);
    }

    #[OA\Operation\Post(
        path: '/api/party/sessions/{publicId}/sync',
        summary: 'Synchronize playback position',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
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
                description: 'Synced position',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'serverPosition' => new OA\Schema(type: 'number'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/sync', name: 'sync', methods: ['POST'])]
    public function sync(string $publicId, #[MapRequestPayload] SyncPlaybackRequest $payload, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $position = $this->unwrapResult($this->commandBus->dispatch(new SyncPlaybackCommand(
            sessionId: $session->getId(),
            userId: Uuid::fromString($user->getId()),
            clientPosition: $payload->clientPosition,
            clientLatency: $payload->clientLatency,
        )));

        return $this->successResponse(['serverPosition' => $position]);
    }

    #[OA\Operation\Delete(
        path: '/api/party/sessions/{publicId}',
        summary: 'End a party session (host only)',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
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
                description: 'Ended',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'The caller is a member of the session but not its host',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'end', methods: ['DELETE'])]
    public function end(string $publicId, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $this->commandBus->dispatch(new EndPartySessionCommand(
            sessionId: $session->getId(),
            userId: Uuid::fromString($user->getId()),
        ));

        return $this->successResponse(['message' => 'Session ended.']);
    }
}
