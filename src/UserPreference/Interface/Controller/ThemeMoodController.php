<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\ThemeMoodPortInterface;
use App\UserPreference\Interface\Request\UpdateThemeMoodRequest;
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
#[Route('/api/user/theme-mood', name: 'theme_mood_')]
final class ThemeMoodController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly ThemeMoodPortInterface $themeMoodPort,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/theme-mood/',
        description: 'Returns the current theme mood for the authenticated user.',
        summary: 'Get theme mood',
        responses: [
            new OA\Response(response: '200', description: 'Theme mood', content: new OA\JsonContent(properties: [new OA\Property(property: 'mood', type: 'string')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        return $this->successResponse([
            'mood' => $this->themeMoodPort->getThemeMood($userId),
        ]);
    }

    #[OA\Put(
        path: '/api/user/theme-mood/',
        summary: 'Update theme mood',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['mood'],
                    properties: [
                        new OA\Property(property: 'mood', type: 'string'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Mood updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'mood', type: 'string')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        $dto = new UpdateThemeMoodRequest($data['mood'] ?? '');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->errorResponse('Invalid theme mood.', 422);
        }

        $this->themeMoodPort->setThemeMood($userId, $dto->mood);

        return $this->successResponse([
            'mood' => $dto->mood,
        ]);
    }
}
