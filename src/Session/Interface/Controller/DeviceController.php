<?php

declare(strict_types=1);

namespace App\Session\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Session\Application\Exception\DeviceNotFound;
use App\Session\Application\Port\DevicePortInterface;
use App\Session\Interface\Request\RegisterDeviceRequest;
use App\Session\Interface\Request\RenameDeviceRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Devices', description: 'Device management for listening sessions')]
#[Route('/api/devices', name: 'device_')]
final class DeviceController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly DevicePortInterface $devicePort,
    ) {}

    #[OA\Operation\Post(
        path: '/api/devices',
        summary: 'Register or touch a device (upsert)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: RegisterDeviceRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device registered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Device registered.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed request body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[Route('', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterDeviceRequest $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $deviceId = Uuid::fromString($request->deviceId);
        $userId = Uuid::fromString($user->getId());
        $this->devicePort->registerDevice($userId, $deviceId, $request->name);

        return $this->successResponse(['message' => 'Device registered.']);
    }

    #[OA\Operation\Get(
        path: '/api/devices',
        summary: 'List all devices registered for the current user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of devices',
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
                                        'deviceId' => new OA\Schema(
                                            description: 'Browser-generated device token; distinct from publicId',
                                            type: 'string',
                                            format: 'uuid',
                                        ),
                                        'name' => new OA\Schema(type: 'string'),
                                        'lastUsedAt' => new OA\Schema(
                                            type: 'string',
                                            format: 'date-time',
                                            nullable: true,
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
        $devices = $this->devicePort->getDevices($userId);

        return $this->successResponse($devices);
    }

    #[OA\Operation\Put(
        path: '/api/devices/{deviceId}',
        summary: 'Rename a device',
        parameters: [
            new OA\Parameter(
                name: 'deviceId',
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
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                    ],
                    required: ['name'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device renamed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Device renamed.'),
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
                description: 'Device not found',
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
    #[Route('/{deviceId}', name: 'rename', methods: ['PUT'])]
    public function rename(string $deviceId, #[MapRequestPayload] RenameDeviceRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        try {
            $devicePublicId = PublicId::fromString($deviceId);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid device ID.');
        }

        try {
            $this->devicePort->renameDevice($userId, $devicePublicId, $payload->name);
        } catch (DeviceNotFound) {
            return $this->notFound('Device not found.');
        }

        return $this->successResponse(['message' => 'Device renamed.']);
    }

    #[OA\Operation\Delete(
        path: '/api/devices/{deviceId}',
        summary: 'Forget (remove) a device',
        parameters: [
            new OA\Parameter(
                name: 'deviceId',
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
                description: 'Device removed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Device forgotten.'),
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
            $devicePublicId = PublicId::fromString($deviceId);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid device ID.');
        }

        $this->devicePort->forgetDevice($userId, $devicePublicId);

        return $this->successResponse(['message' => 'Device forgotten.']);
    }

    private function getCurrentSecurityUser(): ?AuthenticatedUserIdentityInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $user;
    }
}
