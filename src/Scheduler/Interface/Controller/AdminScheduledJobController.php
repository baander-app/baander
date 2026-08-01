<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Controller;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Interface\Request\CreateScheduledJobRequest;
use App\Scheduler\Interface\Request\UpdateScheduledJobRequest;
use App\Scheduler\Interface\Resource\ScheduledJobResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Admin / Scheduler', description: 'Scheduled job management for administrators')]
#[Route('/api/admin/scheduler/jobs', name: 'admin_scheduler_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminScheduledJobController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
        private readonly SchedulerRegistry $registry,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/scheduler/jobs',
        summary: 'List all scheduled jobs',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of scheduled jobs',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        ref: new Model(type: ScheduledJobResource::class),
                    )),
                ]),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $jobs = $this->scheduledJobService->findAll();

        return $this->successResponse(
            array_map(fn($job) => ScheduledJobResource::from($job), $jobs),
        );
    }

    #[OA\Get(
        path: '/api/admin/scheduler/jobs/{id}',
        summary: 'Get a scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Scheduled job details', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs',
        summary: 'Create a scheduled job',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateScheduledJobRequest::class)),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Job created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateScheduledJobRequest $request): JsonResponse
    {
        $job = $this->scheduledJobService->create(
            name: $request->name,
            expression: $request->expression,
            jobType: JobType::from($request->jobType),
            command: $request->command,
            description: $request->description,
            parameters: $request->parameters,
        );

        return $this->successResponse(ScheduledJobResource::from($job), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/admin/scheduler/jobs/{id}',
        summary: 'Update a scheduled job',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateScheduledJobRequest::class)),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Job updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, #[MapRequestPayload] UpdateScheduledJobRequest $request): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $job->update(
            name: $request->name,
            expression: $request->expression,
            jobType: JobType::from($request->jobType),
            command: $request->command,
            description: $request->description,
            parameters: $request->parameters,
        );

        $this->scheduledJobService->save($job);

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Delete(
        path: '/api/admin/scheduler/jobs/{id}',
        summary: 'Delete a scheduled job',
        responses: [
            new OA\Response(response: '204', description: 'Job deleted'),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $this->scheduledJobService->delete($job);

        return $this->noContent();
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs/{id}/pause',
        summary: 'Pause a scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Job paused', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/pause', name: 'pause', methods: ['POST'])]
    public function pause(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $job->pause();
        $this->scheduledJobService->save($job);

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs/{id}/resume',
        summary: 'Resume a paused scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Job resumed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/resume', name: 'resume', methods: ['POST'])]
    public function resume(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $job->resume();
        $this->scheduledJobService->save($job);

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs/{id}/trigger',
        summary: 'Manually trigger a scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Job triggered', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/trigger', name: 'trigger', methods: ['POST'])]
    public function trigger(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        // Dispatch to messenger for immediate execution
        $this->messageBus->dispatch(new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: $job->getJobType()->value,
            command: $job->getCommand(),
            parameters: $job->getParameters(),
        ));

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs/{id}/enable',
        summary: 'Enable a disabled scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Job enabled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/enable', name: 'enable', methods: ['POST'])]
    public function enable(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $job->enable();
        $this->scheduledJobService->save($job);

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Post(
        path: '/api/admin/scheduler/jobs/{id}/disable',
        summary: 'Disable a scheduled job',
        responses: [
            new OA\Response(response: '200', description: 'Job disabled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: ScheduledJobResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
    public function disable(string $id): JsonResponse
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));
        if ($job === null) {
            return $this->notFound('Scheduled job not found.');
        }

        $job->disable();
        $this->scheduledJobService->save($job);

        return $this->successResponse(ScheduledJobResource::from($job));
    }

    #[OA\Get(
        path: '/api/admin/scheduler/jobs/commands',
        summary: 'List available schedulable commands',
        responses: [
            new OA\Response(response: '200', description: 'Available commands', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'messenger', type: 'object'),
                    new OA\Property(property: 'console', type: 'object'),
                ], type: 'object'),
            ])),
        ],
    )]
    #[Route('/commands', name: 'commands', methods: ['GET'])]
    public function commands(): JsonResponse
    {
        return $this->successResponse([
            'messenger' => $this->registry->getMessengerCommands(),
            'console' => $this->registry->getConsoleCommands(),
        ]);
    }
}
