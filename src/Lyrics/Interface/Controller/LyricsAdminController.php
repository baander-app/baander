<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Controller;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
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

    #[OA\Operation\Get(
        path: '/api/admin/lyrics/coverage',
        summary: 'Get lyrics coverage stats',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Lyrics coverage statistics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'totalTracks' => new OA\Schema(type: 'integer'),
                                    'tracksWithLyrics' => new OA\Schema(type: 'integer'),
                                    'tracksWithoutLyrics' => new OA\Schema(type: 'integer'),
                                    'coveragePercentage' => new OA\Schema(type: 'number'),
                                    'bySource' => new OA\Schema(type: 'object'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/coverage', name: 'coverage', methods: ['GET'])]
    #[CliCounterpart('app:lyrics:coverage')]
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getCoverage());
    }

    #[OA\Operation\Post(
        path: '/api/admin/lyrics/bulk-fetch',
        summary: 'Queue a lyrics fetch for every song without lyrics (SUPER_ADMIN only)',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'limit' => new OA\Schema(
                            description: 'Most songs to queue; absent or null queues every song without lyrics',
                            type: 'integer',
                            nullable: true,
                            minimum: 1,
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Bulk fetch triggered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'jobsEnqueued' => new OA\Schema(description: 'Songs queued for fetching', type: 'integer'),
                                    'skippedNoArtist' => new OA\Schema(
                                        description: 'Songs without lyrics not queued because they have no primary artist credit',
                                        type: 'integer',
                                    ),
                                    'skippedNoLength' => new OA\Schema(
                                        description: 'Songs without lyrics not queued because their length is missing or not positive',
                                        type: 'integer',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The run was cancelled in the job monitor while it queued; the fetches it queued are skipped',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Limit is not an integer of at least 1',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden — SUPER_ADMIN only',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
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
            $run = $this->jobs->runInline(new BulkFetchLyricsCommand(limit: $limit));
        } catch (JobCancelledException $cancelled) {
            throw new ConflictException($cancelled->getMessage(), ['reason' => 'cancelled'], $cancelled);
        }

        return $this->successResponse(BulkFetchLyricsResult::fromRun($run)->toArray());
    }

    #[OA\Operation\Get(
        path: '/api/admin/lyrics/sync-status',
        summary: 'Get lyrics sync job status and the latest bulk fetch run',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync status',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'lastSyncAt' => new OA\Schema(type: 'string', nullable: true),
                                    'recentJobs' => new OA\Schema(type: 'integer'),
                                    'failedJobs' => new OA\Schema(type: 'integer'),
                                    'completedJobs' => new OA\Schema(type: 'integer'),
                                    'latestRun' => new OA\Schema(
                                        description: 'The bulk fetch run that finished queueing last, until a day after its last fetch was due; null when there is none. A fetch counts once a worker has handled it.',
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            'jobId' => new OA\Schema(
                                                description: 'Job-monitor ID of the run, or null for a run outside a job',
                                                type: 'string',
                                                nullable: true,
                                            ),
                                            'finishedAt' => new OA\Schema(
                                                description: 'When the run finished queueing',
                                                type: 'string',
                                                format: 'date-time',
                                            ),
                                            'queued' => new OA\Schema(description: 'Fetches the run queued', type: 'integer'),
                                            'found' => new OA\Schema(description: 'Fetches that found lyrics', type: 'integer'),
                                            'noMatch' => new OA\Schema(description: 'Fetches LRCLIB had no lyrics for', type: 'integer'),
                                            'skippedNoArtist' => new OA\Schema(
                                                description: 'Songs not looked up because they have no primary artist credit',
                                                type: 'integer',
                                            ),
                                            'skippedNoLength' => new OA\Schema(
                                                description: 'Songs not looked up because their length is missing or not positive',
                                                type: 'integer',
                                            ),
                                            'rejected' => new OA\Schema(description: 'Fetches whose request LRCLIB rejected', type: 'integer'),
                                            'unavailable' => new OA\Schema(description: 'Fetches that found LRCLIB unavailable', type: 'integer'),
                                        ],
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    #[CliCounterpart('app:lyrics:status')]
    public function syncStatus(): JsonResponse
    {
        return $this->successResponse($this->lyricsAdmin->getSyncStatus());
    }
}
