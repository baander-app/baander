<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\Uuid;
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
use App\Party\Interface\Request\CreatePartySessionRequest;
use App\Party\Interface\Request\SyncPlaybackRequest;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Party\Interface\Resource\PartySessionResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
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

    #[OA\Post(
        path: '/api/party/sessions/',
        summary: 'Create a new watch party session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(ref: new Model(type: CreatePartySessionRequest::class)),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: PartySessionResource::class))])),
            new OA\Response(response: '404', description: 'The video or transcode job does not exist, or the user may not play its video', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error, including a transcode job of another video', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreatePartySessionRequest $payload, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        try {
            $envelope = $this->commandBus->dispatch(new CreatePartySessionCommand(
                hostUserId: Uuid::fromString($user->getId()),
                videoId: Uuid::fromString($payload->videoId),
                transcodeJobId: $payload->transcodeJobId === null ? null : Uuid::fromString($payload->transcodeJobId),
                maxMembers: $payload->maxMembers,
            ));
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

        return $this->created(['data' => PartySessionResource::from($this->unwrapResult($envelope))]);
    }

    #[OA\Get(
        path: '/api/party/sessions/',
        summary: 'List active party sessions',
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'publicId', type: 'string')]))])),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $sessions = $this->sessionPort->findActiveSessions();

        return $this->successResponse(PartySessionResource::collection($sessions));
    }

    #[OA\Get(
        path: '/api/party/sessions/{uuid}',
        summary: 'Get a party session',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'members', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'uuid', type: 'string')]))])])),
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

        $members = $this->memberPort->findBySession($session->getId());

        return $this->successResponse(array_merge(
            PartySessionResource::from($session),
            ['members' => PartyMemberResource::collection($members)],
        ));
    }

    #[OA\Post(
        path: '/api/party/sessions/{uuid}/join',
        summary: 'Join a party session',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Joined', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'nickname', type: 'string')])])),
        ],
    )]
    #[Route('/{uuid}/join', name: 'join', methods: ['POST'])]
    public function join(string $uuid, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $member = $this->unwrapResult($this->commandBus->dispatch(new JoinPartySessionCommand(
            userId: Uuid::fromString($user->getId()),
            sessionId: Uuid::fromString($uuid),
        )));

        return $this->successResponse(PartyMemberResource::from($member));
    }

    #[OA\Post(
        path: '/api/party/sessions/{uuid}/leave',
        summary: 'Leave a party session',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Left', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
        ],
    )]
    #[Route('/{uuid}/leave', name: 'leave', methods: ['POST'])]
    public function leave(string $uuid, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $this->commandBus->dispatch(new LeavePartySessionCommand(
            userId: Uuid::fromString($user->getId()),
            sessionId: Uuid::fromString($uuid),
        ));

        return $this->successResponse(['message' => 'Left session.']);
    }

    #[OA\Post(
        path: '/api/party/sessions/{uuid}/sync',
        summary: 'Synchronize playback position',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Synced position', content: new OA\JsonContent(properties: [new OA\Property(property: 'serverPosition', type: 'number')])),
        ],
    )]
    #[Route('/{uuid}/sync', name: 'sync', methods: ['POST'])]
    public function sync(string $uuid, #[MapRequestPayload] SyncPlaybackRequest $payload, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $position = $this->unwrapResult($this->commandBus->dispatch(new SyncPlaybackCommand(
            sessionId: Uuid::fromString($uuid),
            userId: Uuid::fromString($user->getId()),
            clientPosition: $payload->clientPosition,
            clientLatency: $payload->clientLatency,
        )));

        return $this->successResponse(['serverPosition' => $position]);
    }

    #[OA\Delete(
        path: '/api/party/sessions/{uuid}',
        summary: 'End a party session (host only)',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Ended', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
        ],
    )]
    #[Route('/{uuid}', name: 'end', methods: ['DELETE'])]
    public function end(string $uuid, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $this->commandBus->dispatch(new EndPartySessionCommand(
            sessionId: Uuid::fromString($uuid),
            userId: Uuid::fromString($user->getId()),
        ));

        return $this->successResponse(['message' => 'Session ended.']);
    }
}
