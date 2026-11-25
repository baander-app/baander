<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Interface\Request\TransferHostRequest;
use App\Party\Interface\Request\UpdatePartyMemberRequest;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Party', description: 'Watch party session endpoints')]
#[Route('/api/party/sessions/{publicId}/members', name: 'party_member_')]
final class PartyMemberController
{
    use ApiResponsesTrait;
    use PartyMembershipTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyPublicIdentityPortInterface $identities,
        private readonly PartyMemberPortInterface $memberPort,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/party/sessions/{publicId}/members/',
        summary: 'List members for a party session',
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
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(string $publicId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $members = $this->memberPort->findBySession($session->getId());

        return $this->successResponse(PartyMemberResource::collection(array_map($this->identities->member(...), $members)));
    }

    #[OA\Operation\Patch(
        path: '/api/party/sessions/{publicId}/members/me',
        summary: 'Update current user member preferences',
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
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'audioProfileId' => new OA\Schema(type: 'string', nullable: true),
                        'subtitleTrackId' => new OA\Schema(type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Updated',
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
    #[Route('/me', name: 'update_me', methods: ['PATCH'])]
    public function updateMe(
        string $publicId,
        #[MapRequestPayload]
        UpdatePartyMemberRequest $payload,
    ): JsonResponse {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null) {
            return $this->notFound();
        }

        $member = $this->memberPort->findByUserAndSession(
            Uuid::fromString($user->getId()),
            $session->getId(),
        );
        if ($member === null) {
            return $this->notFound();
        }

        $member->setAudioProfile($payload->audioProfileId);
        $member->setSubtitleTrack($payload->subtitleTrackId);
        $this->memberPort->save($member);

        return $this->successResponse(PartyMemberResource::from($this->identities->member($member)));
    }

    #[OA\Operation\Post(
        path: '/api/party/sessions/{publicId}/members/transfer-host',
        summary: 'Transfer host role to another member',
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
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'newHostUserId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                    ],
                    required: ['newHostUserId'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Host transferred',
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
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'The caller is a member of the session but not its host',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'The session does not exist, or the caller is neither its host nor a member; for the host, no user has the public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The request body is invalid, or the host named themself or a user who is not a member of the session',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/transfer-host', name: 'transfer_host', methods: ['POST'])]
    public function transferHost(
        string $publicId,
        #[MapRequestPayload]
        TransferHostRequest $payload,
    ): JsonResponse {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $this->commandBus->dispatch(new TransferHostCommand(
            sessionId: $session->getId(),
            currentHostUserId: Uuid::fromString($user->getId()),
            newHostPublicId: PublicId::fromString($payload->newHostUserId),
        ));

        return $this->successResponse(['message' => 'Host transferred.']);
    }
}
