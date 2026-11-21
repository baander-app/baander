<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\PreferenceVersionConflict;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\PlayerPreferencesPortInterface;
use App\UserPreference\Interface\Request\PreferenceRequestBody;
use App\UserPreference\Interface\Request\RollbackRequest;
use App\UserPreference\Interface\Request\SavePlayerPreferencesRequest;
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
#[Route('/api/user/player-preferences', name: 'player_preferences_')]
final class PlayerPreferencesController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly PlayerPreferencesPortInterface $playerPreferencesPort,
        private readonly ValidatorInterface $validator,
        private readonly PreferenceRequestBody $requestBody,
        private readonly Security $security,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/user/player-preferences/',
        summary: 'Get player preferences',
        description: 'Returns the player preferences for the authenticated user.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Player preferences',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'payload' => new OA\Schema(
                                properties: [
                                    'shuffle' => new OA\Schema(type: 'boolean'),
                                    'repeat' => new OA\Schema(type: 'string'),
                                    'volume' => new OA\Schema(type: 'number'),
                                    'muted' => new OA\Schema(type: 'boolean'),
                                    'crossfadeEnabled' => new OA\Schema(type: 'boolean'),
                                    'crossfadeDuration' => new OA\Schema(type: 'number'),
                                    'replayGainEnabled' => new OA\Schema(type: 'boolean'),
                                    'replayGainMode' => new OA\Schema(type: 'string'),
                                    'replayGainPreAmp' => new OA\Schema(type: 'number'),
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'No player preferences found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $snapshot = $this->playerPreferencesPort->getSnapshotForUser($userId);

        if ($snapshot === null) {
            return $this->notFound('No player preferences found.');
        }

        return $this->successResponse($snapshot);
    }

    #[OA\Operation\Put(
        path: '/api/user/player-preferences/',
        summary: 'Save player preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(ref: SavePlayerPreferencesRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Preferences saved',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'payload' => new OA\Schema(
                                properties: [
                                    'shuffle' => new OA\Schema(type: 'boolean'),
                                    'repeat' => new OA\Schema(type: 'string'),
                                    'volume' => new OA\Schema(type: 'number'),
                                    'muted' => new OA\Schema(type: 'boolean'),
                                    'crossfadeEnabled' => new OA\Schema(type: 'boolean'),
                                    'crossfadeDuration' => new OA\Schema(type: 'number'),
                                    'replayGainEnabled' => new OA\Schema(type: 'boolean'),
                                    'replayGainMode' => new OA\Schema(type: 'string'),
                                    'replayGainPreAmp' => new OA\Schema(type: 'number'),
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Version conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed or empty JSON body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid request body or preference fields',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
        $dto = new SavePlayerPreferencesRequest(
            payload: $data['payload'],
            version: $data['version'],
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $newVersion = $this->playerPreferencesPort->saveForUser($userId, $dto->payload, $dto->version);
        } catch (PreferenceVersionConflict $e) {
            return $this->errorResponse('Conflict', 409, ['currentVersion' => $e->currentVersion]);
        }

        return $this->successResponse([
            'payload' => $dto->payload,
            'version' => $newVersion,
        ]);
    }

    #[OA\Operation\Get(
        path: '/api/user/player-preferences/history',
        summary: 'Get player preferences history',
        description: 'Returns the version history for player preferences.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Version history',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'history' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/history', name: 'history', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function history(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $history = $this->playerPreferencesPort->getHistory($userId);

        return $this->successResponse(['history' => $history]);
    }

    #[OA\Operation\Post(
        path: '/api/user/player-preferences/rollback',
        summary: 'Rollback player preferences to a previous version',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
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
                        properties: [
                            'payload' => new OA\Schema(
                                properties: [
                                    'shuffle' => new OA\Schema(type: 'boolean'),
                                    'repeat' => new OA\Schema(type: 'string'),
                                    'volume' => new OA\Schema(type: 'number'),
                                    'muted' => new OA\Schema(type: 'boolean'),
                                    'crossfadeEnabled' => new OA\Schema(type: 'boolean'),
                                    'crossfadeDuration' => new OA\Schema(type: 'number'),
                                    'replayGainEnabled' => new OA\Schema(type: 'boolean'),
                                    'replayGainMode' => new OA\Schema(type: 'string'),
                                    'replayGainPreAmp' => new OA\Schema(type: 'number'),
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Concurrent version conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed or empty JSON body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid request body or preference fields',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
            $snapshot = $this->playerPreferencesPort->rollbackTo($userId, $dto->version);
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
