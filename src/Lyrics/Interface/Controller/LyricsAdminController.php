<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Controller;

use App\Auth\Infrastructure\Security\Voter\AdminVoter;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/lyrics', name: 'admin_lyrics_')]
final class LyricsAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly LyricsAdminPortInterface $lyricsAdmin,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/lyrics/coverage',
        summary: 'Get lyrics coverage stats',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Lyrics coverage statistics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'totalTracks', type: 'integer'),
                            new OA\Property(property: 'tracksWithLyrics', type: 'integer'),
                            new OA\Property(property: 'tracksWithoutLyrics', type: 'integer'),
                            new OA\Property(property: 'coveragePercentage', type: 'number'),
                            new OA\Property(property: 'bySource', type: 'object'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/coverage', name: 'coverage', methods: ['GET'])]
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getCoverage());
    }

    #[OA\Post(
        path: '/api/admin/lyrics/bulk-fetch',
        summary: 'Trigger bulk lyrics fetch (SUPER_ADMIN only)',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'limit', type: 'integer', description: 'Max number of jobs to enqueue'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Bulk fetch triggered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'jobsEnqueued', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden — SUPER_ADMIN only', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/bulk-fetch', name: 'bulk_fetch', methods: ['POST'])]
    #[IsGranted(AdminVoter::USER_MANAGEMENT)]
    public function bulkFetch(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR) ?? [];

        $limit = isset($body['limit']) && is_int($body['limit']) ? $body['limit'] : null;

        $count = $this->lyricsAdmin->triggerBulkFetch([], $limit);

        return $this->successResponse(['jobsEnqueued' => $count]);
    }

    #[OA\Get(
        path: '/api/admin/lyrics/sync-status',
        summary: 'Get lyrics sync job status',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'lastSyncAt', type: 'string', nullable: true),
                            new OA\Property(property: 'recentJobs', type: 'integer'),
                            new OA\Property(property: 'failedJobs', type: 'integer'),
                            new OA\Property(property: 'completedJobs', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    public function syncStatus(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getSyncStatus());
    }
}
