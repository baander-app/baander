<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Auth\Infrastructure\Security\Voter\AdminVoter;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/settings', name: 'admin_settings_')]
final class SystemSettingsController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly SystemSettingsPortInterface $settings,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/settings',
        summary: 'Get all system settings',
        responses: [
            new OA\Response(
                response: '200',
                description: 'System settings key-value map',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', additionalProperties: true),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->successResponse($this->settings->all());
    }

    #[OA\Patch(
        path: '/api/admin/settings',
        summary: 'Update system settings (SUPER_ADMIN only)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['settings'],
                properties: [
                    new OA\Property(property: 'settings', type: 'object', description: 'Key-value pairs to upsert'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Updated settings',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', additionalProperties: true),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid request body', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden — SUPER_ADMIN only', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'update', methods: ['PATCH'])]
    #[IsGranted(AdminVoter::SYSTEM_SETTINGS)]
    public function update(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($body) || !array_key_exists('settings', $body) || !is_array($body['settings'])) {
            return $this->errorResponse('Request body must contain a "settings" object.', Response::HTTP_BAD_REQUEST);
        }

        foreach ($body['settings'] as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $this->settings->set($key, $value);
        }

        return $this->successResponse($this->settings->all());
    }
}
