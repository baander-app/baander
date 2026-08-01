<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\Command\StopRadioCommand;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Radio\Interface\Request\StartRadioRequest;
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

#[OA\Tag(name: 'Radio - Session', description: 'Radio playback session')]
#[Route('/api/radio/session', name: 'radio_session_')]
final class RadioSessionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly RadioSessionPortInterface $sessionPort,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/radio/session',
        summary: 'Get current radio session',
        responses: [
            new OA\Response(response: '200', description: 'Current session data', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'stationName', type: 'string'),
                    new OA\Property(property: 'streamUrl', type: 'string', format: 'uri'),
                    new OA\Property(property: 'startedAt', type: 'string', format: 'date-time'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'No active session', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Post(
        path: '/api/radio/session/start',
        summary: 'Start playing a radio station',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stationId', 'streamUrl'],
                properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'streamUrl', type: 'string', format: 'uri'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Radio playing', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'stationId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'streamUrl', type: 'string', format: 'uri'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
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
        $stationId = Uuid::fromString($payload->stationId);

        $envelope = $this->commandBus->dispatch(new StartRadioCommand($userId, $stationId, $payload->streamUrl));
        $handledStamp = $envelope->last(HandledStamp::class);
        $result = $handledStamp?->getResult() ?? [];

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/radio/session/stop',
        summary: 'Stop radio playback',
        responses: [
            new OA\Response(response: '200', description: 'Radio stopped', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', example: null, nullable: true)],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }
}
