<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Controller;

use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Port\RecommendationInsightsPortInterface;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/recommendations', name: 'admin_recommendations_')]
final class RecommendationAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly RecommendationInsightsPortInterface $insights,
        private readonly MessageBusInterface $commandBus,
        private readonly RecommendationJobPortInterface $jobPort,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/recommendations/coverage',
        summary: 'Get recommendation coverage statistics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Coverage statistics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'total_tracks', type: 'integer'),
                            new OA\Property(property: 'tracks_with_recommendations', type: 'integer'),
                            new OA\Property(property: 'tracks_without_recommendations', type: 'integer'),
                            new OA\Property(property: 'coverage_percentage', type: 'number'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/coverage', name: 'coverage', methods: ['GET'])]
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->insights->getCoverage());
    }

    #[OA\Get(
        path: '/api/admin/recommendations/source-quality',
        summary: 'Get recommendation source quality breakdown',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Source quality breakdown',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'by_source_type', type: 'object', additionalProperties: true),
                            new OA\Property(property: 'avg_confidence_score', type: 'number'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/source-quality', name: 'source_quality', methods: ['GET'])]
    public function sourceQuality(): JsonResponse
    {
        return $this->successResponse($this->insights->getSourceQuality());
    }

    #[OA\Get(
        path: '/api/admin/recommendations/freshness',
        summary: 'Get recommendation freshness metrics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Freshness metrics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'avg_age_seconds', type: 'number'),
                            new OA\Property(property: 'last_generated_at', type: 'string', nullable: true),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/freshness', name: 'freshness', methods: ['GET'])]
    public function freshness(): JsonResponse
    {
        return $this->successResponse($this->insights->getFreshness());
    }

    #[OA\Post(
        path: '/api/admin/recommendations/generate',
        summary: 'Trigger recommendation generation',
        description: 'Starts a new recommendation generation job. Returns immediately with job ID for async execution, or results for synchronous execution.',
        responses: [
            new OA\Response(
                response: '202',
                description: 'Generation job started (async)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'job_id', type: 'string'),
                            new OA\Property(property: 'public_id', type: 'string'),
                            new OA\Property(property: 'mode', type: 'string', enum: ['full', 'incremental']),
                            new OA\Property(property: 'status', type: 'string', example: 'pending'),
                            new OA\Property(property: 'execution', type: 'string', enum: ['async', 'sync'], example: 'async'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Generation completed (sync)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'counts', type: 'object', example: ['collaborative' => 150, 'content' => 200, 'genre' => 100]),
                            new OA\Property(property: 'execution', type: 'string', enum: ['sync']),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid mode', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $body = $request->getPayload();
        $mode = $body->get('mode', 'full');

        if (!in_array($mode, ['full', 'incremental'], true)) {
            return $this->errorResponse('Invalid mode. Use "full" or "incremental".', 400);
        }

        $command = new GenerateRecommendationsCommand(mode: $mode);
        $result = $this->commandBus->dispatch($command)->last(HandledStamp::class)?->getResult();

        // If we got a RecommendationJob, it means async execution was used
        if ($result instanceof RecommendationJob) {
            return $this->successResponse([
                'job_id' => $result->getId()->toString(),
                'public_id' => $result->getPublicId()->toString(),
                'mode' => $mode,
                'status' => $result->getStatus()->value,
                'execution' => 'async',
            ], 202);
        }

        // Synchronous execution (CLI fallback or pool not available)
        return $this->successResponse([
            'counts' => $result,
            'execution' => 'sync',
        ]);
    }

    #[OA\Get(
        path: '/api/admin/recommendations/jobs/{publicId}',
        summary: 'Get recommendation job status',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'public_id', type: 'string'),
                            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled']),
                            new OA\Property(property: 'is_full', type: 'boolean'),
                            new OA\Property(property: 'total_songs', type: 'integer'),
                            new OA\Property(property: 'completed_songs', type: 'integer'),
                            new OA\Property(property: 'current_strategy', type: 'string'),
                            new OA\Property(property: 'strategy_counts', type: 'object'),
                            new OA\Property(property: 'progress_percentage', type: 'number'),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'fail_reason', type: 'string', nullable: true),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/jobs/{publicId}', name: 'job_status', methods: ['GET'])]
    public function jobStatus(string $publicId): JsonResponse
    {
        $job = $this->jobPort->getByPublicId(new \App\Shared\Domain\Model\PublicId($publicId));

        if ($job === null) {
            return $this->errorResponse('Job not found', 404);
        }

        $progress = 0.0;
        if ($job->getTotalSongs() > 0) {
            $progress = ($job->getCompletedSongs() / $job->getTotalSongs()) * 100;
        }

        return $this->successResponse([
            'id' => $job->getId()->toString(),
            'public_id' => $job->getPublicId()->toString(),
            'status' => $job->getStatus()->value,
            'is_full' => $job->isFull(),
            'total_songs' => $job->getTotalSongs(),
            'completed_songs' => $job->getCompletedSongs(),
            'current_strategy' => $job->getCurrentStrategy(),
            'strategy_counts' => $job->getStrategyCounts(),
            'progress_percentage' => round($progress, 2),
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'started_at' => $job->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $job->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'fail_reason' => $job->getFailReason(),
            'metadata' => $job->getMetadata(),
            'original_job_id' => $job->getOriginalJobId()?->toString(),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/recommendations/jobs/{publicId}/requeue',
        summary: 'Requeue a failed or cancelled recommendation job',
        description: 'Creates a new job with the same parameters as the original',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Job public ID to requeue', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '201',
                description: 'Job requeued',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'job_id', type: 'string'),
                            new OA\Property(property: 'public_id', type: 'string'),
                            new OA\Property(property: 'mode', type: 'string', enum: ['full', 'incremental']),
                            new OA\Property(property: 'status', type: 'string', example: 'pending'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Job cannot be requeued (not failed/cancelled)', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/jobs/{publicId}/requeue', name: 'job_requeue', methods: ['POST'])]
    public function requeueJob(string $publicId): JsonResponse
    {
        $originalJob = $this->jobPort->getByPublicId(new \App\Shared\Domain\Model\PublicId($publicId));

        if ($originalJob === null) {
            return $this->errorResponse('Job not found', 404);
        }

        $status = $originalJob->getStatus();
        if ($status !== \App\Recommendation\Domain\ValueObject\RecommendationJobStatus::Failed
            && $status !== \App\Recommendation\Domain\ValueObject\RecommendationJobStatus::Cancelled) {
            return $this->errorResponse('Can only requeue failed or cancelled jobs', 400);
        }

        // Preserve metadata and add requeue context
        $metadata = $originalJob->getMetadata();
        $metadata['requeued_from'] = $originalJob->getPublicId()->toString();
        $metadata['requeued_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $metadata['requeued_reason'] = $originalJob->getFailReason() ?? 'manual_requeue';
        if (isset($metadata['original_job_public_id'])) {
            // Track the chain of requeues
            $metadata['requeue_chain'] = $metadata['requeue_chain'] ?? [];
            $metadata['requeue_chain'][] = $metadata['original_job_public_id'];
        }
        $metadata['original_job_public_id'] = $originalJob->getPublicId()->toString();

        $newJob = $this->jobPort->create(
            isFull: $originalJob->isFull(),
            userId: $originalJob->getUserId(),
            metadata: $metadata,
            originalJobId: $originalJob->getId(),
        );

        return $this->successResponse([
            'job_id' => $newJob->getId()->toString(),
            'public_id' => $newJob->getPublicId()->toString(),
            'mode' => $originalJob->isFull() ? 'full' : 'incremental',
            'status' => $newJob->getStatus()->value,
        ], 201);
    }

    #[OA\Delete(
        path: '/api/admin/recommendations/jobs/{publicId}',
        summary: 'Cancel a recommendation job',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Job cancelled'),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/jobs/{publicId}', name: 'job_cancel', methods: ['DELETE'])]
    public function cancelJob(string $publicId): JsonResponse
    {
        $job = $this->jobPort->getByPublicId(new \App\Shared\Domain\Model\PublicId($publicId));

        if ($job === null) {
            return $this->errorResponse('Job not found', 404);
        }

        $job->markCancelled();
        $this->jobPort->save($job);

        return $this->noContent();
    }

    #[OA\Get(
        path: '/api/admin/recommendations/jobs',
        summary: 'List recent recommendation jobs',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Max jobs to return', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'status', description: 'Filter by status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled'])),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of jobs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string'),
                                new OA\Property(property: 'public_id', type: 'string'),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'created_at', type: 'string'),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/jobs', name: 'jobs_list', methods: ['GET'])]
    public function listJobs(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $statusFilter = $request->query->get('status');

        $jobs = $this->jobPort->findRecent($limit, $statusFilter);

        return $this->successResponse(array_map(static fn($job) => [
            'id' => $job->getId()->toString(),
            'public_id' => $job->getPublicId()->toString(),
            'status' => $job->getStatus()->value,
            'is_full' => $job->isFull(),
            'total_songs' => $job->getTotalSongs(),
            'completed_songs' => $job->getCompletedSongs(),
            'current_strategy' => $job->getCurrentStrategy(),
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'started_at' => $job->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $job->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'fail_reason' => $job->getFailReason(),
            'metadata' => $job->getMetadata(),
            'original_job_id' => $job->getOriginalJobId()?->toString(),
        ], $jobs));
    }
}
