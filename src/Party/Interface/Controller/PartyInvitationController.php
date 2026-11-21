<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Party\Application\Command\InvitePartyMemberCommand;
use App\Party\Application\Command\RevokePartyInvitationCommand;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Interface\Resource\PartyInvitationResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The invitations of a party, which its host manages.
 *
 * Every route first answers a caller without a member row as it answers for a party that does
 * not exist. Whether a member is the host is decided after that: by the handlers under the
 * party's row lock for an invite and a revoke, and here for the list.
 */
#[OA\Tag(name: 'Party', description: 'Watch party session endpoints')]
#[Route('/api/party/sessions/{publicId}/invitations', name: 'party_invitation_')]
final class PartyInvitationController
{
    use ApiResponsesTrait;
    use PartyMembershipTrait;

    /** A user's public ID as it appears in a route: 21 characters of the public-ID alphabet. */
    private const string PUBLIC_ID_REQUIREMENT = '[0-9a-zA-Z_-]{21}';

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
        private readonly PartyInvitationPortInterface $invitationPort,
        private readonly PartyPublicIdentityPortInterface $identities,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/party/sessions/{publicId}/invitations/',
        summary: 'List the invitations of a party session',
        description: 'Only the host may list the invitations. They are ordered oldest first.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Party session public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The invitations, oldest first',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: PartyInvitationResource::class),
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

        if (!$session->getHostUserId()->equals(Uuid::fromString($user->getId()))) {
            throw new AccessDeniedException('Only the host may manage the party\'s invitations.');
        }

        $invitations = $this->invitationPort->findBySession($session->getId());

        return $this->successResponse(PartyInvitationResource::collection($this->identities->invitations($invitations)));
    }

    #[OA\Operation\Put(
        path: '/api/party/sessions/{publicId}/invitations/{userPublicId}',
        summary: 'Invite a user to a party session',
        description: 'Only the host may invite. Inviting a user who is invited already changes nothing.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Party session public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
            ),
            new OA\Parameter(
                name: 'userPublicId',
                in: 'path',
                description: "The invited user's public ID",
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'The user is invited'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                description: 'The session does not exist, or the caller is neither its host nor a member; for the host, no user has the public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The host named themself',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{userPublicId}', name: 'invite', requirements: ['userPublicId' => self::PUBLIC_ID_REQUIREMENT], methods: ['PUT'])]
    public function invite(string $publicId, string $userPublicId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $this->commandBus->dispatch(new InvitePartyMemberCommand(
            sessionId: $session->getId(),
            hostUserId: Uuid::fromString($user->getId()),
            userPublicId: PublicId::fromString($userPublicId),
        ));

        return $this->noContent();
    }

    #[OA\Operation\Delete(
        path: '/api/party/sessions/{publicId}/invitations/{userPublicId}',
        summary: "Revoke a user's invitation to a party session",
        description: 'Only the host may revoke. A revoked user who had joined stops being a member of the session. Revoking a user who is not invited changes nothing.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Party session public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
            ),
            new OA\Parameter(
                name: 'userPublicId',
                in: 'path',
                description: "The revoked user's public ID",
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'The user is neither invited nor a member'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                description: 'The session does not exist, or the caller is neither its host nor a member; for the host, no user has the public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The host named themself',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{userPublicId}', name: 'revoke', requirements: ['userPublicId' => self::PUBLIC_ID_REQUIREMENT], methods: ['DELETE'])]
    public function revoke(string $publicId, string $userPublicId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null || !$this->belongsTo($session->getId(), $user)) {
            return $this->notFound();
        }

        $this->commandBus->dispatch(new RevokePartyInvitationCommand(
            sessionId: $session->getId(),
            hostUserId: Uuid::fromString($user->getId()),
            userPublicId: PublicId::fromString($userPublicId),
        ));

        return $this->noContent();
    }
}
