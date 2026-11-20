<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\LogViewer\LogViewerInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Request\Log\LogFilterRequest;
use App\Shared\Interface\Request\Log\LogSqlRequest;
use App\Shared\Interface\Resource\Log\LogSourcesResource;
use App\Shared\Interface\Resource\Log\LogEntriesResource;
use App\Shared\Interface\Resource\Log\LogRecordResource;
use App\Shared\Interface\Resource\Log\LogSqlResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/logs', name: 'admin_logs_')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
final readonly class AdminLogController
{
    use ApiResponsesTrait;

    public function __construct(private LogViewerInterface $logs)
    {
    }

    #[Route('/sources', name: 'sources', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:log:sources')]
    #[OA\Get(path: '/api/admin/logs/sources', summary: 'List configured log sources and host coverage', responses: [new OA\Response(response: 200, description: 'Source inventory', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: LogSourcesResource::class))]))])]
    public function sources(): JsonResponse
    {
        return $this->successResponse(LogSourcesResource::from($this->logs->sources()));
    }

    #[Route('/entries', name: 'entries', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:log:view')]
    #[OA\Get(path: '/api/admin/logs/entries', summary: 'Browse a bounded frozen log snapshot', parameters: [
        new OA\Parameter(name: 'sources', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'levels', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'channels[]', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string')), style: 'form', explode: true),
        new OA\Parameter(name: 'correlationId', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'since', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'until', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 500, minimum: 1)),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
    ], responses: [new OA\Response(response: 200, description: 'Log snapshot page', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: LogEntriesResource::class))]))])]
    public function entries(Request $request): JsonResponse
    {
        return $this->successResponse(LogEntriesResource::from($this->logs->entries(LogFilterRequest::fromQuery($request->query->all())->query())));
    }

    #[Route('/entries/{id}', name: 'detail', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:log:view')]
    #[OA\Get(path: '/api/admin/logs/entries/{id}', summary: 'Inspect a retained log record', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Retained log record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: LogRecordResource::class))]))])]
    public function detail(string $id): JsonResponse
    {
        return $this->successResponse(LogRecordResource::from($this->logs->detail($id)));
    }

    #[Route('/query', name: 'query', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:log:query')]
    #[OA\Post(path: '/api/admin/logs/query', summary: 'Execute read-only SQL against a frozen log snapshot', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: LogSqlRequest::class))), responses: [new OA\Response(response: 200, description: 'SQL rows and snapshot coverage', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: LogSqlResource::class))]))])]
    public function query(#[MapRequestPayload] LogSqlRequest $request): JsonResponse
    {
        return $this->successResponse(LogSqlResource::from($this->logs->query($request->query())));
    }
}
