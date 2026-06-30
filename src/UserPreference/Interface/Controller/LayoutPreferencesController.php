<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\LayoutPreferencesPortInterface;
use App\UserPreference\Interface\Request\RollbackRequest;
use App\UserPreference\Interface\Request\SaveLayoutPreferencesRequest;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
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
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/layout-preferences/',
        description: 'Returns the layout preferences for the authenticated user.',
        summary: 'Get layout preferences',
        responses: [
            new OA\Response(response: '200', description: 'Layout preferences', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', properties: [
                    new OA\Property(property: 'mode', type: 'string'),
                    new OA\Property(property: 'activeTab', type: 'string'),
                ]),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'No layout preferences found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $payload = $this->layoutPreferencesPort->getForUser($userId);

        if ($payload === null) {
            return $this->notFound('No layout preferences found.');
        }

        $version = $this->layoutPreferencesPort->getVersion($userId);

        return $this->successResponse([
            'payload' => $payload,
            'version' => $version,
        ]);
    }

    #[OA\Put(
        path: '/api/user/layout-preferences/',
        summary: 'Save layout preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['payload', 'version'],
                    properties: [
                        new OA\Property(property: 'payload', type: 'object'),
                        new OA\Property(property: 'version', type: 'integer'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Preferences saved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', properties: [
                    new OA\Property(property: 'mode', type: 'string'),
                    new OA\Property(property: 'activeTab', type: 'string'),
                ]),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Version conflict', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        $dto = new SaveLayoutPreferencesRequest(
            payload: $data['payload'] ?? [],
            version: $data['version'] ?? 0,
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $newVersion = $this->layoutPreferencesPort->saveForUser($userId, $dto->payload, $dto->version);
        } catch (\RuntimeException $e) {
            $currentVersion = $this->layoutPreferencesPort->getVersion($userId);

            return $this->errorResponse('Conflict', 409, ['currentVersion' => $currentVersion]);
        }

        return $this->successResponse([
            'payload' => $dto->payload,
            'version' => $newVersion,
        ]);
    }

    #[OA\Get(
        path: '/api/user/layout-preferences/history',
        description: 'Returns the version history for layout preferences.',
        summary: 'Get layout preferences history',
        responses: [
            new OA\Response(response: '200', description: 'Version history', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'history', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'version', type: 'integer'),
                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                ])),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Post(
        path: '/api/user/layout-preferences/rollback',
        summary: 'Rollback layout preferences to a previous version',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['version'],
                    properties: [
                        new OA\Property(property: 'version', type: 'integer'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Preferences rolled back', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', properties: [
                    new OA\Property(property: 'mode', type: 'string'),
                    new OA\Property(property: 'activeTab', type: 'string'),
                ]),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/rollback', name: 'rollback', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function rollback(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        $dto = new RollbackRequest($data['version'] ?? 0);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $payload = $this->layoutPreferencesPort->rollbackTo($userId, $dto->version);
        $version = $this->layoutPreferencesPort->getVersion($userId);

        return $this->successResponse([
            'payload' => $payload,
            'version' => $version,
        ]);
    }

    private function getUserId(): Uuid
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();

        return Uuid::fromString($user->getId());
    }
}
