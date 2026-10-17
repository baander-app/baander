<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Interface\Request\TransferHostRequest;
use App\Party\Interface\Request\UpdatePartyMemberRequest;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
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

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyPublicIdentityPortInterface $identities,
        private readonly PartyMemberPortInterface $memberPort,
    ) {
    }

    #[OA\Get(
        path: '/api/party/sessions/{publicId}/members/',
        summary: 'List members for a party session',
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')]))])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(string $publicId): JsonResponse
    {
        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null) {
            return $this->notFound();
        }

        $members = $this->memberPort->findBySession($session->getId());

        return $this->successResponse(PartyMemberResource::collection(array_map($this->identities->member(...), $members)));
    }

    #[OA\Patch(
        path: '/api/party/sessions/{publicId}/members/me',
        summary: 'Update current user member preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'audioProfileId', type: 'string', nullable: true),
                        new OA\Property(property: 'subtitleTrackId', type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')])])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/me', name: 'update_me', methods: ['PATCH'])]
    public function updateMe(
        string $publicId,
        #[MapRequestPayload] UpdatePartyMemberRequest $payload,
    ): JsonResponse
    {
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

    #[OA\Post(
        path: '/api/party/sessions/{publicId}/members/transfer-host',
        summary: 'Transfer host role to another member',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['newHostUserId'],
                properties: [
                    new OA\Property(property: 'newHostUserId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'),
                ],
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Host transferred', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/transfer-host', name: 'transfer_host', methods: ['POST'])]
    public function transferHost(
        string $publicId,
        #[MapRequestPayload] TransferHostRequest $payload,
    ): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $session = $this->sessionPort->findByPublicIdentifier($publicId);
        if ($session === null) {
            return $this->notFound();
        }

        $newHostUserId = $this->identities->userId(PublicId::fromString($payload->newHostUserId));

        $this->commandBus->dispatch(new TransferHostCommand(
            sessionId: $session->getId(),
            currentHostUserId: Uuid::fromString($user->getId()),
            newHostUserId: $newHostUserId,
        ));

        return $this->successResponse(['message' => 'Host transferred.']);
    }
}
