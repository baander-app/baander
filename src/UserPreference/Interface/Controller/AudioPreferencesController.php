<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\PreferenceVersionConflict;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\AudioPreferencesPortInterface;
use App\UserPreference\Interface\Request\PreferenceRequestBody;
use App\UserPreference\Interface\Request\RollbackRequest;
use App\UserPreference\Interface\Request\SaveAudioPreferencesRequest;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[OA\Tag(name: 'User Preferences', description: 'User preference management endpoints')]
#[Route('/api/user/audio-preferences', name: 'audio_preferences_')]
final class AudioPreferencesController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AudioPreferencesPortInterface $audioPreferencesPort,
        private readonly ValidatorInterface $validator,
        private readonly PreferenceRequestBody $requestBody,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/audio-preferences/',
        description: 'Returns the audio preferences for the authenticated user. Payload shape follows schema version 2 (flexible JSONB).',
        summary: 'Get audio preferences',
        responses: [
            new OA\Response(response: '200', description: 'Audio preferences', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'payload', type: 'object', description: 'Flexible JSONB payload. See SaveAudioPreferencesRequest for full schema.'),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'No audio preferences found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $snapshot = $this->audioPreferencesPort->getSnapshotForUser($userId);

        if ($snapshot === null) {
            return $this->notFound('No audio preferences found.');
        }

        return $this->successResponse($snapshot);
    }

    #[OA\Put(
        path: '/api/user/audio-preferences/',
        summary: 'Save audio preferences',
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
                new OA\Property(property: 'payload', type: 'object', description: 'Flexible JSONB payload. Echoed back from request.'),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Version conflict', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Malformed or empty JSON body', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid request body or preference fields', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request): JsonResponse
    {
        $userId = $this->getUserId();

        $data = $this->requestBody->save((string) $request->getContent());
        $dto = new SaveAudioPreferencesRequest(
            payload: $data['payload'],
            version: $data['version'],
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $newVersion = $this->audioPreferencesPort->saveForUser($userId, $dto->payload, $dto->version);
        } catch (PreferenceVersionConflict $e) {
            return $this->errorResponse('Conflict', 409, ['currentVersion' => $e->currentVersion]);
        }

        return $this->successResponse([
            'payload' => $dto->payload,
            'version' => $newVersion,
        ]);
    }

    #[OA\Get(
        path: '/api/user/audio-preferences/history',
        description: 'Returns the version history for audio preferences.',
        summary: 'Get audio preferences history',
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

        $history = $this->audioPreferencesPort->getHistory($userId);

        return $this->successResponse(['history' => $history]);
    }

    #[OA\Post(
        path: '/api/user/audio-preferences/rollback',
        summary: 'Rollback audio preferences to a previous version',
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
                new OA\Property(property: 'payload', type: 'object', description: 'Flexible JSONB payload at the rolled-back version.'),
                new OA\Property(property: 'version', type: 'integer'),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Concurrent version conflict', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Malformed or empty JSON body', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid request body or preference fields', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            $snapshot = $this->audioPreferencesPort->rollbackTo($userId, $dto->version);
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
