<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Port\AccentColorPortInterface;
use App\UserPreference\Interface\Request\UpdateAccentColorRequest;
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
#[Route('/api/user/accent-color', name: 'accent_color_')]
final class AccentColorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AccentColorPortInterface $accentColorPort,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/accent-color/',
        description: 'Returns the current accent color for the authenticated user.',
        summary: 'Get accent color',
        responses: [
            new OA\Response(response: '200', description: 'Accent color', content: new OA\JsonContent(properties: [new OA\Property(property: 'color', type: 'string')])),
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
            'color' => $this->accentColorPort->getAccentColor($userId),
        ]);
    }

    #[OA\Put(
        path: '/api/user/accent-color/',
        summary: 'Update accent color',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['color'],
                    properties: [
                        new OA\Property(property: 'color', type: 'string'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Color updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'color', type: 'string')])),
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
        $dto = new UpdateAccentColorRequest($data['color'] ?? '');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->errorResponse('Invalid accent color.', 422);
        }

        $this->accentColorPort->setAccentColor($userId, $dto->color);

        return $this->successResponse([
            'color' => $dto->color,
        ]);
    }
}
