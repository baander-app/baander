<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Controller;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
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
        private readonly JobMonitorAdministrationInterface $jobs,
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
    #[CliCounterpart('app:lyrics:coverage')]
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getCoverage());
    }

    #[OA\Post(
        path: '/api/admin/lyrics/bulk-fetch',
        summary: 'Queue a lyrics fetch for every song without lyrics (SUPER_ADMIN only)',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'limit', type: 'integer', minimum: 1, nullable: true, description: 'Most songs to queue; absent or null queues every song without lyrics'),
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
                            new OA\Property(property: 'jobsEnqueued', description: 'Songs queued for fetching', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '409', description: 'The run was cancelled in the job monitor while it queued; the fetches it queued are skipped', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Limit is not an integer of at least 1', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden — SUPER_ADMIN only', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/bulk-fetch', name: 'bulk_fetch', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:lyrics:fetch')]
    public function bulkFetch(Request $request): JsonResponse
    {
        // The admin page posts no body: that queues every song without lyrics.
        $content = $request->getContent();
        $body = $content !== '' ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
        $limit = is_array($body) ? ($body['limit'] ?? null) : null;
        if ($limit !== null && !is_int($limit)) {
            throw new InvalidInputException('The limit must be an integer.', ['limit' => $limit]);
        }

        // Run as a job, as app:lyrics:fetch does, so the job monitor can cancel its queued fetches.
        try {
            $queued = $this->jobs->runInline(new BulkFetchLyricsCommand(limit: $limit))->result;
        } catch (JobCancelledException $cancelled) {
            throw new ConflictException($cancelled->getMessage(), ['reason' => 'cancelled'], $cancelled);
        }

        return $this->successResponse(['jobsEnqueued' => $queued]);
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
    #[CliCounterpart('app:lyrics:status')]
    public function syncStatus(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getSyncStatus());
    }
}
