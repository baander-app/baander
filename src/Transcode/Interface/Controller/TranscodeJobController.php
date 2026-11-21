<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\DTO\TranscodeCleanupResult;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\PlaybackAccessInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Query\TranscodeJobQueryPort;
use App\Transcode\Interface\Resource\TranscodeJobResource;
use App\Transcode\Interface\Resource\TranscodeMetricsResource;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[OA\Tag(name: 'Transcode', description: 'Video transcoding management endpoints')]
#[Route('/api/transcode/jobs', name: 'transcode_job_')]
final class TranscodeJobController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodePublicViewInterface $publicView,
        private readonly PlaybackAccessInterface $playback,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/transcode/jobs/',
        summary: 'List orphaned transcode jobs in accessible libraries',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'publicId' => new OA\Schema(type: 'string'),
                                        'status' => new OA\Schema(type: 'string'),
                                        'qualityTier' => new OA\Schema(type: 'string'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $orphans = $this->jobPort->findOrphanedJobs();

        $data = [];
        foreach ($orphans as $job) {
            if ($this->canAccessVideo($job->getVideoId())) {
                $data[] = TranscodeJobResource::from($this->publicView->job($job));
            }
        }

        return $this->successResponse($data);
    }

    #[OA\Operation\Get(
        path: '/api/transcode/jobs/{publicId}',
        summary: 'Get a transcode job by public ID',
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
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(type: 'string'),
                                    'status' => new OA\Schema(type: 'string'),
                                    'qualityTier' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $job = $this->jobPort->findByPublicId(PublicId::fromString($publicId));

        if ($job === null) {
            return $this->notFound();
        }
        $this->playback->assertAccess($job->getVideoId());

        return $this->successResponse(TranscodeJobResource::from($this->publicView->job($job)));
    }

    #[OA\Operation\Post(
        path: '/api/transcode/jobs/cleanup',
        summary: 'Expire stale transcode sessions and clean up orphaned transcode jobs (no active sessions)',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Cleanup result',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'expired' => new OA\Schema(type: 'integer'),
                                    'cleaned' => new OA\Schema(type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The run removed every job it could and left at least one behind; `details` holds the `expired`, `cleaned` and `failed` counts, and the server log names each job that failed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/cleanup', name: 'cleanup', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:transcode:job:cleanup')]
    public function cleanup(): JsonResponse
    {
        // A run that left jobs behind ends with an outcome the exception subscriber answers as 503,
        // with the run's counts as the error details.
        $result = $this->commandBus->dispatch(new CleanupOrphanedJobsCommand())->last(HandledStamp::class)?->getResult();
        if (!$result instanceof TranscodeCleanupResult) {
            throw new \LogicException('The transcode job cleanup did not return its result.');
        }

        return $this->successResponse($result->toArray());
    }

    #[OA\Operation\Get(
        path: '/api/transcode/jobs/{publicId}/metrics',
        summary: 'Get transcode job metrics',
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
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: TranscodeMetricsResource::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/metrics', name: 'metrics', methods: ['GET'])]
    public function getMetrics(string $publicId): JsonResponse
    {
        $job = $this->jobPort->findByPublicId(PublicId::fromString($publicId));
        if ($job === null) {
            return $this->notFound();
        }
        $this->playback->assertAccess($job->getVideoId());
        return $this->successResponse(TranscodeMetricsResource::from($this->publicView->job($job)));
    }

    #[OA\Operation\Get(
        path: '/api/transcode/jobs/list',
        summary: 'List transcode jobs for the authenticated user',
        responses: [
            new OA\Response(response: '200', description: 'Success'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/list', name: 'list', methods: ['GET'], priority: 1)]
    public function listJobs(TranscodeJobQueryPort $queryPort, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $jobs = $queryPort->findByUser(Uuid::fromString($user->getId()));
        $data = [];
        foreach ($jobs as $job) {
            $view = $job->toArray();
            $videoId = $this->publicView->videoId(PublicId::fromString($view['videoId']));
            if ($videoId !== null && $this->canAccessVideo($videoId)) {
                $data[] = $view;
            }
        }

        return $this->successResponse($data);
    }

    private function canAccessVideo(Uuid $videoId): bool
    {
        try {
            $this->playback->assertAccess($videoId);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
