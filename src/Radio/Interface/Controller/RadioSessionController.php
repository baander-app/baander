<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Radio\Application\Port\RadioStationPortInterface;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\Command\StopRadioCommand;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Radio\Interface\Request\StartRadioRequest;
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

#[OA\Tag(name: 'Radio - Session', description: 'Radio playback session')]
#[Route('/api/radio/session', name: 'radio_session_')]
final class RadioSessionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly RadioSessionPortInterface $sessionPort,
        private readonly MessageBusInterface $commandBus,
        private readonly RadioStationPortInterface $stations,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/radio/session',
        summary: 'Get current radio session',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Current session data',
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
                                    'stationName' => new OA\Schema(type: 'string'),
                                    'streamUrl' => new OA\Schema(type: 'string', format: 'uri'),
                                    'startedAt' => new OA\Schema(type: 'string', format: 'date-time'),
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
                description: 'No active session',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
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
            return $this->notFound('No active radio session.');
        }

        return $this->successResponse($session);
    }

    #[OA\Operation\Post(
        path: '/api/radio/session/start',
        summary: 'Start playing a radio station',
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
                        'streamUrl' => new OA\Schema(type: 'string', format: 'uri'),
                    ],
                    required: ['stationId', 'streamUrl'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Radio playing',
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
                                    'streamUrl' => new OA\Schema(type: 'string', format: 'uri'),
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
    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(#[MapRequestPayload] StartRadioRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $stationId = $this->stationId($payload->stationId);

        $envelope = $this->commandBus->dispatch(new StartRadioCommand($userId, $stationId, $payload->streamUrl));
        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result);
    }

    #[OA\Operation\Post(
        path: '/api/radio/session/stop',
        summary: 'Stop radio playback',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Radio stopped',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                nullable: true,
                                example: null,
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
    #[Route('/stop', name: 'stop', methods: ['POST'])]
    public function stop(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        $envelope = $this->commandBus->dispatch(new StopRadioCommand($userId));
        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result);
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
