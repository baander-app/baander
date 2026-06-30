<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Interface\Request\TransferHostRequest;
use App\Party\Interface\Request\UpdatePartyMemberRequest;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Party', description: 'Watch party session endpoints')]
#[Route('/api/party/sessions/{uuid}/members', name: 'party_member_')]
final class PartyMemberController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
    )
    {
    }

    #[OA\Get(
        path: '/api/party/sessions/{uuid}/members/',
        summary: 'List members for a party session',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'nickname', type: 'string')]))])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(string $uuid): JsonResponse
    {
        $session = $this->sessionPort->findByUuid(Uuid::fromString($uuid));
        if ($session === null) {
            return $this->notFound();
        }

        $members = $this->memberPort->findBySession($session->getId());

        return $this->successResponse(PartyMemberResource::collection($members));
    }

    #[OA\Patch(
        path: '/api/party/sessions/{uuid}/members/me',
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
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'nickname', type: 'string')])])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/me', name: 'update_me', methods: ['PATCH'])]
    public function updateMe(string $uuid, #[MapRequestPayload] UpdatePartyMemberRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $member = $this->memberPort->findByUserAndSession(
            Uuid::fromString($user->getId()),
            Uuid::fromString($uuid),
        );
        if ($member === null) {
            return $this->notFound();
        }

        $member->setAudioProfile($payload->audioProfileId);
        $member->setSubtitleTrack($payload->subtitleTrackId);
        $this->memberPort->save($member);

        return $this->successResponse(PartyMemberResource::from($member));
    }

    #[OA\Post(
        path: '/api/party/sessions/{uuid}/members/transfer-host',
        summary: 'Transfer host role to another member',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['newHostUserId'],
                properties: [
                    new OA\Property(property: 'newHostUserId', type: 'string', format: 'uuid'),
                ],
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Host transferred', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/transfer-host', name: 'transfer_host', methods: ['POST'])]
    public function transferHost(string $uuid, #[MapRequestPayload] TransferHostRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $newHostUserId = Uuid::fromString($payload->newHostUserId);

        $this->commandBus->dispatch(new TransferHostCommand(
            sessionId: Uuid::fromString($uuid),
            currentHostUserId: Uuid::fromString($user->getId()),
            newHostUserId: $newHostUserId,
        ));

        return $this->successResponse(['message' => 'Host transferred.']);
    }
}
