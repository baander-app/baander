<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Radio\Application\Port\RadioStationPortInterface;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Command\StarStationCommand;
use App\Radio\Application\Command\UnstarStationCommand;
use App\Radio\Application\Port\StarredStationPortInterface;
use App\Radio\Interface\Request\StarStationRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
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
        private readonly RadioStationPortInterface $stations,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/radio/starred',
        summary: 'List starred stations',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of starred stations',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'userId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'stationId' => new OA\Schema(
                                            description: 'The station public ID; GET /api/radio/stations?publicIds= reads the station',
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'starredAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    ],
                                    required: ['userId', 'stationId', 'starredAt'],
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

    #[OA\Operation\Post(
        path: '/api/radio/starred',
        summary: 'Star a station',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'stationId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                    ],
                    required: ['stationId'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Station starred',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'stationId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                    'starred' => new OA\Schema(type: 'boolean'),
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
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
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
        $stationId = $this->stationId($payload->stationId);

        $envelope = $this->commandBus->dispatch(new StarStationCommand($userId, $stationId));
        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->created($result);
    }

    #[OA\Operation\Delete(
        path: '/api/radio/starred/{stationId}',
        summary: 'Unstar a station',
        parameters: [
            new OA\Parameter(
                name: 'stationId',
                in: 'path',
                description: 'Station public identifier',
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
            new OA\Response(response: '204', description: 'Station unstarred'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
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

        $this->commandBus->dispatch(new UnstarStationCommand($userId, $this->stationId($stationId)));

        return $this->noContent();
    }

    private function getCurrentSecurityUser(): ?AuthenticatedUserIdentityInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $user;
    }
    private function stationId(string $publicId): Uuid
    {
        try {
            $station = $this->stations->findByPublicId(PublicId::fromString($publicId));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Radio station not found.');
        }

        return ($station ?? throw new NotFoundHttpException('Radio station not found.'))->getId();
    }

}
