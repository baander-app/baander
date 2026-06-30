<?php

declare(strict_types=1);

namespace App\QoL\Interface\Controller;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ValueError;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/qol', name: 'admin_qol_')]
final class QoLAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly QoLAdminPortInterface $adminPort,
    )
    {
    }

    #[OA\Get(
        path: '/api/admin/qol/status',
        summary: 'Get stream governor status',
        responses: [
            new OA\Response(response: '200', description: 'Governor status'),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->successResponse($this->adminPort->getStatus());
    }

    #[OA\Get(
        path: '/api/admin/qol/streams',
        summary: 'List active streams with budget allocations',
        responses: [
            new OA\Response(response: '200', description: 'Active streams'),
            new OA\Response(response: '403', description: 'Forbidden'),
        ],
    )]
    #[Route('/streams', name: 'streams', methods: ['GET'])]
    public function streams(): JsonResponse
    {
        return $this->successResponse($this->adminPort->getActiveStreams());
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[OA\Patch(
        path: '/api/admin/qol/profile',
        summary: 'Update algorithm profile',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'profile', type: 'string', enum: ['conservative', 'balanced', 'aggressive']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Profile updated'),
            new OA\Response(response: '400', description: 'Invalid profile'),
        ],
    )]
    #[Route('/profile', name: 'profile', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $profileName = $request->getPayload()->getString('profile');

        if ($profileName === '') {
            return $this->errorResponse('Missing profile parameter', 400);
        }

        try {
            $result = $this->adminPort->setProfile($profileName);
        } catch (ValueError) {
            return $this->errorResponse(sprintf('Invalid profile: %s', $profileName), 400);
        }

        return $this->successResponse(['profile' => $result]);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[OA\Post(
        path: '/api/admin/qol/reset',
        summary: 'Reset learning data and return to Learning state',
        responses: [
            new OA\Response(response: '200', description: 'Learning reset'),
        ],
    )]
    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        return $this->successResponse(['state' => $this->adminPort->resetLearning()]);
    }
}
