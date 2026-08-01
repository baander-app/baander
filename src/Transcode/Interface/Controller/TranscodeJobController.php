<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Query\TranscodeJobQueryPort;
use App\Transcode\Interface\Resource\TranscodeJobResource;
use App\Transcode\Interface\Resource\TranscodeMetricsResource;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Transcode', description: 'Video transcoding management endpoints')]
#[Route('/api/transcode/jobs', name: 'transcode_job_')]
final class TranscodeJobController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/transcode/jobs/',
        summary: 'List all transcode jobs',
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'qualityTier', type: 'string')]))])),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $orphans = $this->jobPort->findOrphanedJobs();

        return $this->successResponse(TranscodeJobResource::collection($orphans));
    }

    #[OA\Get(
        path: '/api/transcode/jobs/{publicId}',
        summary: 'Get a transcode job by public ID',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'qualityTier', type: 'string')])])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $job = $this->jobPort->findByPublicId(PublicId::fromString($publicId));

        if ($job === null) {
            return $this->notFound();
        }

        return $this->successResponse(TranscodeJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/transcode/jobs/cleanup',
        summary: 'Clean up orphaned transcode jobs (no active sessions)',
        responses: [
            new OA\Response(response: '200', description: 'Cleanup result', content: new OA\JsonContent(properties: [new OA\Property(property: 'cleaned', type: 'integer')])),
        ],
    )]
    #[Route('/cleanup', name: 'cleanup', methods: ['POST'])]
    public function cleanup(): JsonResponse
    {
        $stamp = $this->commandBus->dispatch(new CleanupOrphanedJobsCommand())->last(HandledStamp::class);
        $count = $stamp?->getResult() ?? 0;

        return $this->successResponse(['cleaned' => $count]);
    }

    #[OA\Get(
        path: '/api/transcode/jobs/{publicId}/metrics',
        summary: 'Get transcode job metrics',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(ref: new Model(type: TranscodeMetricsResource::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/metrics', name: 'metrics', methods: ['GET'])]
    public function getMetrics(string $publicId): JsonResponse
    {
        $job = $this->jobPort->findByPublicId(PublicId::fromString($publicId));
        if ($job === null) {
            return $this->notFound();
        }
        return $this->successResponse(TranscodeMetricsResource::from($job));
    }

    #[OA\Get(
        path: '/api/transcode/jobs/list',
        summary: 'List transcode jobs for the authenticated user',
        responses: [
            new OA\Response(response: '200', description: 'Success'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listJobs(TranscodeJobQueryPort $queryPort): JsonResponse
    {
        $user = $this->security->getUser();
        $jobs = $queryPort->findByUser(Uuid::fromString($user->getId()));
        return $this->successResponse(array_map(fn($d) => $d->toArray(), $jobs));
    }
}
