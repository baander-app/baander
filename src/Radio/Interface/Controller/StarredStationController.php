<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Radio\Application\Command\StarStationCommand;
use App\Radio\Application\Command\UnstarStationCommand;
use App\Radio\Application\Port\StarredStationPortInterface;
use App\Radio\Interface\Request\StarStationRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Radio - Starred', description: 'Star/unstar radio stations')]
#[Route('/api/radio/starred', name: 'radio_starred_')]
final class StarredStationController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly StarredStationPortInterface $starredPort,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/radio/starred',
        summary: 'List starred stations',
        responses: [
            new OA\Response(response: '200', description: 'List of starred stations', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'countryCode', type: 'string'),
                    new OA\Property(property: 'streamUrl', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'starredAt', type: 'string', format: 'date-time'),
                ], type: 'object'))],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        return $this->successResponse($this->starredPort->listStarred($userId));
    }

    #[OA\Post(
        path: '/api/radio/starred',
        summary: 'Star a station',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stationId'],
                properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Station starred', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'starred', type: 'boolean'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'star', methods: ['POST'])]
    public function star(#[MapRequestPayload] StarStationRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $stationId = Uuid::fromString($payload->stationId);

        $envelope = $this->commandBus->dispatch(new StarStationCommand($userId, $stationId));
        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->created($result);
    }

    #[OA\Delete(
        path: '/api/radio/starred/{stationId}',
        summary: 'Unstar a station',
        parameters: [
            new OA\Parameter(name: 'stationId', description: 'Station UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Station unstarred'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{stationId}', name: 'unstar', methods: ['DELETE'])]
    public function unstar(string $stationId): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        $this->commandBus->dispatch(new UnstarStationCommand($userId, Uuid::fromString($stationId)));

        return $this->noContent();
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
