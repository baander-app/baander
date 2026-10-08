<?php

declare(strict_types=1);

namespace App\QoL\Interface\Controller;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The stream governor of every HTTP worker, read and changed through the server
 * control channel. Each answer has one row per worker; `missing_workers` names
 * the workers that did not answer and `worker_errors` those whose call failed.
 * A change that did not reach every worker answers 503 with the same report.
 *
 * @phpstan-import-type StatusReport from QoLAdminPortInterface
 */
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
        summary: 'Get the stream governor status of every web server worker',
        responses: [
            new OA\Response(response: '200', description: 'One status row per worker, the total of active streams, and the workers that did not answer or failed'),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:qol:status')]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->successResponse($this->adminPort->getStatus());
    }

    #[OA\Get(
        path: '/api/admin/qol/streams',
        summary: 'List the active streams of every web server worker with their budget allocations',
        responses: [
            new OA\Response(response: '200', description: 'Active streams per worker, their total, and the workers that did not answer or failed'),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:qol:streams')]
    #[Route('/streams', name: 'streams', methods: ['GET'])]
    public function streams(): JsonResponse
    {
        return $this->successResponse($this->adminPort->getActiveStreams());
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[OA\Patch(
        path: '/api/admin/qol/profile',
        summary: 'Set the algorithm profile in every web server worker',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'profile', type: 'string', enum: ['conservative', 'balanced', 'aggressive']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Profile set and saved; one status row per worker'),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Missing or unknown profile; no worker was changed', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'The change did not reach every worker; `details` holds the per-worker report with `missing_workers` and `worker_errors`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:qol:profile')]
    #[Route('/profile', name: 'profile', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        return $this->changeResponse(
            $this->adminPort->setProfile($request->getPayload()->getString('profile')),
            'The profile change did not reach every web server worker.',
        );
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[OA\Post(
        path: '/api/admin/qol/reset',
        summary: 'Reset learning data in every web server worker and return each to the Learning state',
        responses: [
            new OA\Response(response: '200', description: 'Learning reset and saved; one status row per worker'),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'The reset did not reach every worker; `details` holds the per-worker report with `missing_workers` and `worker_errors`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:qol:reset')]
    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        return $this->changeResponse(
            $this->adminPort->resetLearning(),
            'The learning reset did not reach every web server worker.',
        );
    }

    /** @param StatusReport $report */
    private function changeResponse(array $report, string $partialMessage): JsonResponse
    {
        if ($report['missing_workers'] !== [] || $report['worker_errors'] !== []) {
            return $this->errorResponse($partialMessage, Response::HTTP_SERVICE_UNAVAILABLE, $report);
        }

        return $this->successResponse($report);
    }
}
