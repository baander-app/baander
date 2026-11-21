<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Controller;

use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\RequeueRecommendationJobCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Port\RecommendationInsightsPortInterface;
use App\Recommendation\Application\Query\GetRecommendationJobQuery;
use App\Recommendation\Application\Query\ListRecommendationJobsQuery;
use App\Recommendation\Interface\Resource\RecommendationGenerationResource;
use App\Recommendation\Interface\Resource\RecommendationJobResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recommendation insights and jobs. The app:recommendation:* console commands reach the
 * same port and Application messages, so both paths apply the same rules.
 */
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/recommendations', name: 'admin_recommendations_')]
final class RecommendationAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly RecommendationInsightsPortInterface $insights,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/recommendations/coverage',
        summary: 'Get recommendation coverage statistics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Coverage statistics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'total_tracks' => new OA\Schema(type: 'integer'),
                                    'tracks_with_recommendations' => new OA\Schema(type: 'integer'),
                                    'tracks_without_recommendations' => new OA\Schema(type: 'integer'),
                                    'coverage_percentage' => new OA\Schema(type: 'number'),
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
    #[CliCounterpart('app:recommendation:stats')]
    #[Route('/coverage', name: 'coverage', methods: ['GET'])]
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->insights->getCoverage());
    }

    #[OA\Operation\Get(
        path: '/api/admin/recommendations/source-quality',
        summary: 'Get recommendation source quality breakdown',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Source quality breakdown',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'by_source_type' => new OA\Schema(type: 'object', additionalProperties: true),
                                    'avg_confidence_score' => new OA\Schema(type: 'number'),
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
    #[CliCounterpart('app:recommendation:stats')]
    #[Route('/source-quality', name: 'source_quality', methods: ['GET'])]
    public function sourceQuality(): JsonResponse
    {
        return $this->successResponse($this->insights->getSourceQuality());
    }

    #[OA\Operation\Get(
        path: '/api/admin/recommendations/freshness',
        summary: 'Get recommendation freshness metrics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Freshness metrics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'avg_age_seconds' => new OA\Schema(type: 'number'),
                                    'last_generated_at' => new OA\Schema(type: 'string', nullable: true),
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
    #[CliCounterpart('app:recommendation:stats')]
    #[Route('/freshness', name: 'freshness', methods: ['GET'])]
    public function freshness(): JsonResponse
    {
        return $this->successResponse($this->insights->getFreshness());
    }

    #[OA\Operation\Post(
        path: '/api/admin/recommendations/generate',
        summary: 'Trigger recommendation generation',
        description: 'Creates a recommendation job. In the web server the job runs on the CPU process pool and the response returns at once with the pending job; without the pool the job runs during the request and the response carries its counts.',
        responses: [
            new OA\Response(
                response: '202',
                description: 'Generation job started (async)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'job_id' => new OA\Schema(type: 'string'),
                                    'public_id' => new OA\Schema(type: 'string'),
                                    'mode' => new OA\Schema(
                                        type: 'string',
                                        enum: ['full', 'incremental'],
                                    ),
                                    'status' => new OA\Schema(type: 'string', example: 'pending'),
                                    'execution' => new OA\Schema(
                                        type: 'string',
                                        enum: ['async'],
                                        example: 'async',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Generation ran during the request (sync)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'job_id' => new OA\Schema(type: 'string'),
                                    'public_id' => new OA\Schema(type: 'string'),
                                    'mode' => new OA\Schema(
                                        type: 'string',
                                        enum: ['full', 'incremental'],
                                    ),
                                    'status' => new OA\Schema(
                                        type: 'string',
                                        enum: ['completed', 'cancelled'],
                                    ),
                                    'counts' => new OA\Schema(
                                        type: 'object',
                                        example: ['collaborative' => 150, 'content' => 200, 'genre' => 100],
                                    ),
                                    'execution' => new OA\Schema(
                                        type: 'string',
                                        enum: ['sync'],
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid mode',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[CliCounterpart('app:recommendation:generate')]
    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentUser] UserInterface $user): JsonResponse
    {
        $mode = $request->getPayload()->get('mode', GenerateRecommendationsCommand::MODE_FULL);

        $result = $this->dispatch(new GenerateRecommendationsCommand(
            mode: (string) $mode,
            actor: $user->getUserIdentifier(),
        ));
        assert($result instanceof RecommendationGenerationResult);

        return $this->successResponse(RecommendationGenerationResource::from($result), $result->isAsync() ? 202 : 200);
    }

    #[OA\Operation\Get(
        path: '/api/admin/recommendations/jobs/{publicId}',
        summary: 'Get recommendation job status',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Job public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job details',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: RecommendationJobResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[CliCounterpart('app:recommendation:job:show')]
    #[Route('/jobs/{publicId}', name: 'job_status', methods: ['GET'])]
    public function jobStatus(string $publicId): JsonResponse
    {
        return $this->successResponse(RecommendationJobResource::from($this->dispatch(new GetRecommendationJobQuery($publicId))));
    }

    #[OA\Operation\Post(
        path: '/api/admin/recommendations/jobs/{publicId}/requeue',
        summary: 'Requeue a failed or cancelled recommendation job',
        description: 'Creates a new job with the same parameters as the original and starts it like a generated job.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Job public ID to requeue',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '201',
                description: 'Job requeued and started',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'job_id' => new OA\Schema(type: 'string'),
                                    'public_id' => new OA\Schema(type: 'string'),
                                    'mode' => new OA\Schema(
                                        type: 'string',
                                        enum: ['full', 'incremental'],
                                    ),
                                    'status' => new OA\Schema(type: 'string', example: 'pending'),
                                    'execution' => new OA\Schema(
                                        type: 'string',
                                        enum: ['async', 'sync'],
                                    ),
                                    'counts' => new OA\Schema(description: 'Only when the job ran during the request', type: 'object'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Job cannot be requeued (not failed or cancelled)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[CliCounterpart('app:recommendation:job:requeue')]
    #[Route('/jobs/{publicId}/requeue', name: 'job_requeue', methods: ['POST'])]
    public function requeueJob(string $publicId, #[CurrentUser] UserInterface $user): JsonResponse
    {
        $result = $this->dispatch(new RequeueRecommendationJobCommand($publicId, $user->getUserIdentifier()));
        assert($result instanceof RecommendationGenerationResult);

        return $this->successResponse(RecommendationGenerationResource::from($result), 201);
    }

    #[OA\Operation\Delete(
        path: '/api/admin/recommendations/jobs/{publicId}',
        summary: 'Cancel a recommendation job',
        description: 'Cancelling a cancelled job succeeds without change.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Job public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Job cancelled'),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Job already completed or failed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[CliCounterpart('app:recommendation:job:cancel')]
    #[Route('/jobs/{publicId}', name: 'job_cancel', methods: ['DELETE'])]
    public function cancelJob(string $publicId): JsonResponse
    {
        $this->dispatch(new CancelRecommendationJobCommand($publicId));

        return $this->noContent();
    }

    #[OA\Operation\Get(
        path: '/api/admin/recommendations/jobs',
        summary: 'List recent recommendation jobs',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Max jobs to return, 1-100',
                schema: new OA\Schema(type: 'integer', default: 20),
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                description: 'Filter by status',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled'],
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of jobs, newest first',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: RecommendationJobResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Unknown status',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
    #[CliCounterpart('app:recommendation:job:list')]
    #[Route('/jobs', name: 'jobs_list', methods: ['GET'])]
    public function listJobs(Request $request): JsonResponse
    {
        $status = $request->query->get('status');

        $jobs = $this->dispatch(new ListRecommendationJobsQuery(
            limit: (int) $request->query->get('limit', ListRecommendationJobsQuery::DEFAULT_LIMIT),
            status: $status === null || $status === '' ? null : (string) $status,
        ));
        assert(is_array($jobs));

        return $this->successResponse(RecommendationJobResource::collection($jobs));
    }

    private function dispatch(object $message): mixed
    {
        return $this->commandBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
