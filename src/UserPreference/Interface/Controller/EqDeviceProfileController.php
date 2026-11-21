<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Exception\EqDeviceProfileNotFound;
use App\UserPreference\Application\Port\EqDeviceProfilePortInterface;
use App\UserPreference\Interface\Request\CreateEqDeviceProfileRequest;
use App\UserPreference\Interface\Request\UpdateEqDeviceProfileRequest;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[OA\Tag(name: 'EQ Device Profiles', description: 'Named device EQ profile management')]
#[Route('/api/user/eq-profiles', name: 'eq_profiles_')]
final class EqDeviceProfileController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly EqDeviceProfilePortInterface $profilePort,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/user/eq-profiles/',
        summary: 'List all EQ device profiles for the authenticated user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'profiles' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'publicId' => new OA\Schema(type: 'string'),
                                        'name' => new OA\Schema(type: 'string'),
                                        'icon' => new OA\Schema(type: 'string'),
                                        'isDefault' => new OA\Schema(type: 'boolean'),
                                        'sortOrder' => new OA\Schema(type: 'integer'),
                                        'version' => new OA\Schema(type: 'integer'),
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
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(): JsonResponse
    {
        $userId = $this->getUserId();
        $profiles = $this->profilePort->listProfiles($userId);

        return $this->successResponse(['profiles' => $profiles]);
    }

    #[OA\Operation\Post(
        path: '/api/user/eq-profiles/',
        summary: 'Create a new EQ device profile',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                        'icon' => new OA\Schema(type: 'string'),
                        'deviceId' => new OA\Schema(type: 'string', nullable: true),
                    ],
                    required: ['name', 'icon'],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Profile created'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid input',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): JsonResponse
    {
        $userId = $this->getUserId();
        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');

        $dto = new CreateEqDeviceProfileRequest(
            name: $data['name'] ?? '',
            icon: $data['icon'] ?? 'custom',
            deviceId: $data['deviceId'] ?? null,
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $profile = $this->profilePort->createProfile(
            $userId,
            $dto->name,
            $dto->icon,
            $dto->deviceId,
            [],
        );

        return $this->successResponse($profile, 201);
    }

    #[OA\Operation\Get(
        path: '/api/user/eq-profiles/{id}',
        summary: 'Get a specific EQ device profile',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile details',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: \App\UserPreference\Interface\Resource\EqDeviceProfileResource::class),
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
                description: 'Profile not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(string $id): JsonResponse
    {
        try {
            $profile = $this->profilePort->getProfile($this->getUserId(), $this->profileId($id));
        } catch (EqDeviceProfileNotFound) {
            return $this->notFound();
        }

        return $this->successResponse($profile);
    }

    #[OA\Operation\Put(
        path: '/api/user/eq-profiles/{id}',
        summary: 'Update an EQ device profile',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                        'icon' => new OA\Schema(type: 'string'),
                        'deviceId' => new OA\Schema(type: 'string', nullable: true),
                        'payload' => new OA\Schema(type: 'object'),
                        'sortOrder' => new OA\Schema(type: 'integer'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: \App\UserPreference\Interface\Resource\EqDeviceProfileResource::class),
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
                description: 'Profile not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid input',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');

        $dto = new UpdateEqDeviceProfileRequest(
            name: $data['name'] ?? null,
            icon: $data['icon'] ?? null,
            deviceId: $data['deviceId'] ?? null,
            payload: $data['payload'] ?? null,
            sortOrder: $data['sortOrder'] ?? null,
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $profile = $this->profilePort->updateProfile(
                $this->getUserId(),
                $this->profileId($id),
                $dto->name,
                $dto->icon,
                $dto->deviceId,
                $dto->payload,
                $dto->sortOrder,
            );
        } catch (EqDeviceProfileNotFound) {
            return $this->notFound();
        }

        return $this->successResponse($profile);
    }

    #[OA\Operation\Delete(
        path: '/api/user/eq-profiles/{id}',
        summary: 'Delete an EQ device profile (cannot delete default)',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile deleted',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'deleted' => new OA\Schema(type: 'boolean'),
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
                description: 'Profile not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Cannot delete default profile',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->profilePort->deleteProfile($this->getUserId(), $this->profileId($id));
        } catch (EqDeviceProfileNotFound) {
            return $this->notFound();
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(['deleted' => true]);
    }

    #[OA\Operation\Post(
        path: '/api/user/eq-profiles/{id}/activate',
        summary: 'Set a profile as the active profile for the current user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile activated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: \App\UserPreference\Interface\Resource\EqDeviceProfileResource::class),
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
                description: 'Profile not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function activate(string $id): JsonResponse
    {
        $userId = $this->getUserId();
        try {
            $result = $this->profilePort->activateProfile($userId, $this->profileId($id));
        } catch (EqDeviceProfileNotFound) {
            return $this->notFound();
        }

        return $this->successResponse($result);
    }

    private function profileId(string $id): PublicId
    {
        try {
            return PublicId::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new EqDeviceProfileNotFound();
        }
    }

    private function getUserId(): Uuid
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();

        return Uuid::fromString($user->getId());
    }
}
