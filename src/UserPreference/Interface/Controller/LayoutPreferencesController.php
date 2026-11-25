<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\UserPreference\Application\Exception\PreferenceVersionConflict;
use App\UserPreference\Application\Port\LayoutPreferencesPortInterface;
use App\UserPreference\Interface\Request\PreferenceRequestBody;
use App\UserPreference\Interface\Request\RollbackRequest;
use App\UserPreference\Interface\Request\SaveLayoutPreferencesRequest;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[OA\Tag(name: 'User Preferences', description: 'User preference management endpoints')]
#[Route('/api/user/layout-preferences', name: 'layout_preferences_')]
final class LayoutPreferencesController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly LayoutPreferencesPortInterface $layoutPreferencesPort,
        private readonly ValidatorInterface $validator,
        private readonly PreferenceRequestBody $requestBody,
        private readonly Security $security,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/user/layout-preferences/',
        summary: 'Get layout preferences',
        description: 'Returns the layout preferences for the authenticated user.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Layout preferences',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'payload' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'mode' => new OA\Schema(type: 'string'),
                                    'activeTab' => new OA\Schema(type: 'string'),
                                ],
                            ),
                            'version' => new OA\Schema(type: 'integer'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'No layout preferences found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $snapshot = $this->layoutPreferencesPort->getSnapshotForUser($userId);

        if ($snapshot === null) {
            return $this->notFound('No layout preferences found.');
        }

        return $this->successResponse($snapshot);
    }

    #[OA\Operation\Put(
        path: '/api/user/layout-preferences/',
        summary: 'Save layout preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(ref: SaveLayoutPreferencesRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Preferences saved',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'payload' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'mode' => new OA\Schema(type: 'string'),
                                    'activeTab' => new OA\Schema(type: 'string'),
                                ],
                            ),
                            'version' => new OA\Schema(type: 'integer'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Version conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed or empty JSON body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid request body or preference fields',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $data = $this->requestBody->save((string) $request->getContent());
        $dto = new SaveLayoutPreferencesRequest(
            payload: $data['payload'],
            version: $data['version'],
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $newVersion = $this->layoutPreferencesPort->saveForUser($userId, $dto->payload, $dto->version);
        } catch (PreferenceVersionConflict $e) {
            return $this->errorResponse('Conflict', 409, ['currentVersion' => $e->currentVersion]);
        }

        return $this->successResponse([
            'payload' => $dto->payload,
            'version' => $newVersion,
        ]);
    }

    #[OA\Operation\Get(
        path: '/api/user/layout-preferences/history',
        summary: 'Get layout preferences history',
        description: 'Returns the version history for layout preferences.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Version history',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'history' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'version' => new OA\Schema(type: 'integer'),
                                        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
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
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/history', name: 'history', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function history(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $history = $this->layoutPreferencesPort->getHistory($userId);

        return $this->successResponse(['history' => $history]);
    }

    #[OA\Operation\Post(
        path: '/api/user/layout-preferences/rollback',
        summary: 'Rollback layout preferences to a previous version',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'version' => new OA\Schema(type: 'integer'),
                    ],
                    required: ['version'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Preferences rolled back',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'payload' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'mode' => new OA\Schema(type: 'string'),
                                    'activeTab' => new OA\Schema(type: 'string'),
                                ],
                            ),
                            'version' => new OA\Schema(type: 'integer'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Concurrent version conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed or empty JSON body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid request body or preference fields',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/rollback', name: 'rollback', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function rollback(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $dto = new RollbackRequest($this->requestBody->rollback((string) $request->getContent()));

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $snapshot = $this->layoutPreferencesPort->rollbackTo($userId, $dto->version);
        } catch (PreferenceVersionConflict $e) {
            return $this->errorResponse('Conflict', 409, ['currentVersion' => $e->currentVersion]);
        }
        return $this->successResponse($snapshot);
    }

    private function getUserId(): Uuid
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();

        return Uuid::fromString($user->getId());
    }
}
