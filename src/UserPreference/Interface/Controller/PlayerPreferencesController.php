<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\PlayerPreferencesPortInterface;
use App\UserPreference\Interface\Request\RollbackRequest;
use App\UserPreference\Interface\Request\SavePlayerPreferencesRequest;
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
#[Route('/api/user/player-preferences', name: 'player_preferences_')]
final class PlayerPreferencesController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly PlayerPreferencesPortInterface $playerPreferencesPort,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/player-preferences/',
        description: 'Returns the player preferences for the authenticated user.',
        summary: 'Get player preferences',
        responses: [
            new OA\Response(response: '200', description: 'Player preferences', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', properties: [
                    new OA\Property(property: 'shuffle', type: 'boolean'),
                    new OA\Property(property: 'repeat', type: 'string'),
                    new OA\Property(property: 'volume', type: 'number'),
                    new OA\Property(property: 'muted', type: 'boolean'),
                    new OA\Property(property: 'crossfadeEnabled', type: 'boolean'),
                    new OA\Property(property: 'crossfadeDuration', type: 'number'),
                    new OA\Property(property: 'replayGainEnabled', type: 'boolean'),
                    new OA\Property(property: 'replayGainMode', type: 'string'),
                    new OA\Property(property: 'replayGainPreAmp', type: 'number'),
                ]),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'No player preferences found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $payload = $this->playerPreferencesPort->getForUser($userId);

        if ($payload === null) {
            return $this->notFound('No player preferences found.');
        }

        $version = $this->playerPreferencesPort->getVersion($userId);

        return $this->successResponse([
            'payload' => $payload,
            'version' => $version,
        ]);
    }

    #[OA\Put(
        path: '/api/user/player-preferences/',
        summary: 'Save player preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['payload', 'version'],
                    properties: [
                        new OA\Property(property: 'payload', properties: [
                            new OA\Property(property: 'shuffle', type: 'boolean'),
                            new OA\Property(property: 'repeat', type: 'string', enum: ['off', 'all', 'one']),
                            new OA\Property(property: 'volume', type: 'number', minimum: 0, maximum: 1),
                            new OA\Property(property: 'muted', type: 'boolean'),
                            new OA\Property(property: 'crossfadeEnabled', type: 'boolean'),
                            new OA\Property(property: 'crossfadeDuration', type: 'number', minimum: 0, maximum: 12),
                            new OA\Property(property: 'replayGainEnabled', type: 'boolean'),
                            new OA\Property(property: 'replayGainMode', type: 'string', enum: ['track', 'album']),
                            new OA\Property(property: 'replayGainPreAmp', type: 'number', minimum: -15, maximum: 15),
                        ]),
                        new OA\Property(property: 'version', type: 'integer', minimum: 1),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Preferences saved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', properties: [
                    new OA\Property(property: 'shuffle', type: 'boolean'),
                    new OA\Property(property: 'repeat', type: 'string'),
                    new OA\Property(property: 'volume', type: 'number'),
                    new OA\Property(property: 'muted', type: 'boolean'),
                    new OA\Property(property: 'crossfadeEnabled', type: 'boolean'),
                    new OA\Property(property: 'crossfadeDuration', type: 'number'),
                    new OA\Property(property: 'replayGainEnabled', type: 'boolean'),
                    new OA\Property(property: 'replayGainMode', type: 'string'),
                    new OA\Property(property: 'replayGainPreAmp', type: 'number'),
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
        $dto = new SavePlayerPreferencesRequest(
            payload: $data['payload'] ?? [],
            version: $data['version'] ?? 0,
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $newVersion = $this->playerPreferencesPort->saveForUser($userId, $dto->payload, $dto->version);
        } catch (\RuntimeException $e) {
            $currentVersion = $this->playerPreferencesPort->getVersion($userId);

            return $this->errorResponse('Conflict', 409, ['currentVersion' => $currentVersion]);
        }

        return $this->successResponse([
            'payload' => $dto->payload,
            'version' => $newVersion,
        ]);
    }

    #[OA\Get(
        path: '/api/user/player-preferences/history',
        description: 'Returns the version history for player preferences.',
        summary: 'Get player preferences history',
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

        $history = $this->playerPreferencesPort->getHistory($userId);

        return $this->successResponse(['history' => $history]);
    }

    #[OA\Post(
        path: '/api/user/player-preferences/rollback',
        summary: 'Rollback player preferences to a previous version',
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
                    new OA\Property(property: 'shuffle', type: 'boolean'),
                    new OA\Property(property: 'repeat', type: 'string'),
                    new OA\Property(property: 'volume', type: 'number'),
                    new OA\Property(property: 'muted', type: 'boolean'),
                    new OA\Property(property: 'crossfadeEnabled', type: 'boolean'),
                    new OA\Property(property: 'crossfadeDuration', type: 'number'),
                    new OA\Property(property: 'replayGainEnabled', type: 'boolean'),
                    new OA\Property(property: 'replayGainMode', type: 'string'),
                    new OA\Property(property: 'replayGainPreAmp', type: 'number'),
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

        $payload = $this->playerPreferencesPort->rollbackTo($userId, $dto->version);
        $version = $this->playerPreferencesPort->getVersion($userId);

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
