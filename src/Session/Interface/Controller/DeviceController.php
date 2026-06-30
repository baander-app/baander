<?php

declare(strict_types=1);

namespace App\Session\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Interface\Request\RegisterDeviceRequest;
use App\Session\Interface\Request\RenameDeviceRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Devices', description: 'Device management for listening sessions')]
#[Route('/api/devices', name: 'device_')]
final class DeviceController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    #[OA\Post(
        path: '/api/devices',
        summary: 'Register or touch a device (upsert)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['deviceId'],
                properties: [
                    new OA\Property(property: 'deviceId', type: 'string', format: 'uuid', description: 'Persistent device identifier from localStorage'),
                    new OA\Property(property: 'name', type: 'string', example: 'Living Room Speaker'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device registered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object',
                        properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Device registered.'),
                        ]
                    ),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $deviceIdString = $body['deviceId'] ?? null;
        $name = $body['name'] ?? 'Device';

        if ($deviceIdString === null || $deviceIdString === '') {
            return $this->errorResponse('deviceId is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $deviceId = Uuid::fromString($deviceIdString);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid deviceId.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = Uuid::fromString($user->getId());
        $this->sessionPort->registerDevice($userId, $deviceId, $name);

        return $this->successResponse(['message' => 'Device registered.']);
    }

    #[OA\Get(
        path: '/api/devices',
        summary: 'List all devices registered for the current user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of devices',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                            ],
                        )),
                    ],
                ),
            ),
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
        $devices = $this->sessionPort->getDevices($userId);

        return $this->successResponse($devices);
    }

    #[OA\Put(
        path: '/api/devices/{deviceId}',
        summary: 'Rename a device',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                ],
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device renamed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Device renamed.'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{deviceId}', name: 'rename', methods: ['PUT'])]
    public function rename(string $deviceId, #[MapRequestPayload] RenameDeviceRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        try {
            $deviceUuid = Uuid::fromString($deviceId);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid device ID.');
        }

        $this->sessionPort->renameDevice($userId, $deviceUuid, $payload->name);

        return $this->successResponse(['message' => 'Device renamed.']);
    }

    #[OA\Delete(
        path: '/api/devices/{deviceId}',
        summary: 'Forget (remove) a device',
        parameters: [
            new OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Device forgotten.'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{deviceId}', name: 'forget', methods: ['DELETE'])]
    public function forget(string $deviceId): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        try {
            $deviceUuid = Uuid::fromString($deviceId);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid device ID.');
        }

        $this->sessionPort->forgetDevice($userId, $deviceUuid);

        return $this->successResponse(['message' => 'Device forgotten.']);
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
