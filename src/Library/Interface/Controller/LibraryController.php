<?php

declare(strict_types=1);

namespace App\Library\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\Command\DeleteLibraryCommand;
use App\Library\Application\Command\ScanAllLibrariesCommand;
use App\Library\Application\Command\StartLibraryScanCommand;
use App\Library\Application\Command\UpdateLibraryCommand;
use App\Library\Application\PathValidator;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Library\Application\Query\GetLibraryQuery;
use App\Library\Application\Query\GetLibraryStatsQuery;
use App\Library\Application\Query\ListLibrariesQuery;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Request\CreateLibraryRequest;
use App\Library\Interface\Request\UpdateLibraryRequest;
use App\Library\Interface\Resource\LibraryResource;
use App\Library\Interface\Resource\LibraryScanAllResource;
use App\Library\Interface\Resource\PathValidationResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Library', description: 'Media library management endpoints')]
#[Route('/api/libraries', name: 'library_')]
final class LibraryController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly LibraryReadScopeProviderInterface $readScopes,
        private readonly PathValidator $pathValidator,
        private readonly ?Security $security = null,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/libraries',
        summary: 'List all libraries',
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'query',
                description: 'Filter by library type',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'],
                ),
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
                                type: 'array',
                                items: new OA\Schema(ref: LibraryResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid type filter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    #[CliCounterpart('app:library:list')]
    public function index(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        $libraries = $this->dispatch(new ListLibrariesQuery($this->readScopes->current(), $type !== null ? (string) $type : null));

        return $this->successResponse($this->readsServerPaths()
            ? LibraryResource::collection($libraries)
            : array_map(LibraryResource::forMember(...), $libraries));
    }

    #[OA\Operation\Post(
        path: '/api/libraries',
        summary: 'Create a new library (admin)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                        'path' => new OA\Schema(type: 'string'),
                        'type' => new OA\Schema(
                            type: 'string',
                            enum: [LibraryType::class],
                        ),
                        'filesystemType' => new OA\Schema(
                            type: 'string',
                            enum: [FilesystemType::class],
                            default: 'local',
                        ),
                        'sortOrder' => new OA\Schema(type: 'integer'),
                        'slug' => new OA\Schema(type: 'string', nullable: true),
                    ],
                    required: ['name', 'path', 'type'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Created',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: LibraryResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Slug already exists',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'store', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:create')]
    public function store(#[MapRequestPayload] CreateLibraryRequest $payload): JsonResponse
    {
        $user = $this->security?->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        try {
            $userId = Uuid::fromString($user->getId());
        } catch (\InvalidArgumentException) {
            return $this->unauthorized();
        }

        $library = $this->dispatch(new CreateLibraryCommand(
            name: $payload->name,
            path: $payload->path,
            type: $payload->type,
            filesystemType: $payload->filesystemType,
            slug: $payload->slug,
            sortOrder: $payload->sortOrder,
            grantTo: $userId,
        ));

        return $this->successResponse(LibraryResource::from($library), Response::HTTP_CREATED);
    }

    #[OA\Operation\Get(
        path: '/api/libraries/{id}',
        summary: 'Get a single library',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'Library public ID or slug',
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
                            'data' => new OA\Schema(ref: LibraryResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[CliCounterpart('app:library:show')]
    public function show(string $id): JsonResponse
    {
        $library = $this->dispatch(new GetLibraryQuery($id, $this->readScopes->current()));

        return $this->successResponse($this->readsServerPaths()
            ? LibraryResource::from($library)
            : LibraryResource::forMember($library));
    }

    #[OA\Operation\Patch(
        path: '/api/libraries/{id}',
        summary: 'Rename or reorder a library (admin)',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'Library public ID or slug',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string', nullable: true),
                        'sortOrder' => new OA\Schema(type: 'integer', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: LibraryResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:update')]
    public function update(#[MapRequestPayload] UpdateLibraryRequest $payload, string $id): JsonResponse
    {
        $library = $this->dispatch(new UpdateLibraryCommand($id, $payload->name, $payload->sortOrder));

        return $this->successResponse(LibraryResource::from($library));
    }

    #[OA\Operation\Delete(
        path: '/api/libraries/{id}',
        summary: 'Delete a library (admin)',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'Library public ID or slug',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Deleted',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(nullable: true, example: null),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:delete')]
    public function destroy(string $id): JsonResponse
    {
        $this->dispatch(new DeleteLibraryCommand($id));

        return $this->json(['data' => null]);
    }

    #[OA\Operation\Post(
        path: '/api/libraries/{id}/scan',
        summary: 'Trigger a library scan (admin)',
        description: 'Claims the library for a scan and dispatches an asynchronous scan job. The scan runs in the background; follow it in the job monitor and in the scan status of the library. A library that is already scanning answers 409.',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'Library public ID or slug',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '202',
                description: 'Scan dispatched',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: LibraryResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Scan already in progress',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}/scan', name: 'scan', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:scan')]
    public function scan(string $id, Request $request): JsonResponse
    {
        // Read from payload so it works for both JSON bodies (axios) and form data.
        $rescan = $request->getPayload()->getBoolean('rescan', false);
        $library = $this->dispatch(new StartLibraryScanCommand($id, $rescan));

        return $this->successResponse(LibraryResource::from($library), Response::HTTP_ACCEPTED);
    }

    #[OA\Operation\Get(
        path: '/api/libraries/{id}/stats',
        summary: 'Get library statistics',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'Library public ID or slug',
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
                                    'songs' => new OA\Schema(type: 'integer', example: 1234),
                                    'albums' => new OA\Schema(type: 'integer', example: 100),
                                    'artists' => new OA\Schema(type: 'integer', example: 50),
                                    'genres' => new OA\Schema(type: 'integer', example: 20),
                                    'totalSize' => new OA\Schema(
                                        description: 'Total file size in bytes',
                                        type: 'integer',
                                        example: 53687091200,
                                    ),
                                    'totalDuration' => new OA\Schema(
                                        description: 'Total duration in seconds',
                                        type: 'number',
                                        example: 259200.5,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{id}/stats', name: 'stats', methods: ['GET'])]
    #[CliCounterpart('app:library:stats')]
    public function stats(string $id): JsonResponse
    {
        return $this->successResponse($this->dispatch(new GetLibraryStatsQuery($id, $this->readScopes->current())));
    }

    #[OA\Operation\Post(
        path: '/api/libraries/validate-path',
        summary: 'Validate a library path (admin)',
        description: 'Checks whether a filesystem path exists and is readable. Use before creating a library. Requires the administrator role.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'path' => new OA\Schema(type: 'string', example: '/mnt/media/music'),
                    ],
                    required: ['path'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Validation result',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'valid' => new OA\Schema(type: 'boolean'),
                                    'error' => new OA\Schema(type: 'string', nullable: true),
                                    'resolvedPath' => new OA\Schema(type: 'string', nullable: true),
                                    'exists' => new OA\Schema(type: 'boolean'),
                                    'readable' => new OA\Schema(type: 'boolean'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Path is missing',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/validate-path', name: 'validate_path', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:validate-path')]
    public function validatePath(Request $request): JsonResponse
    {
        $path = $request->getPayload()->get('path');

        return $this->successResponse(PathValidationResource::from($this->pathValidator->validateInput(is_string($path) ? $path : '')));
    }

    #[OA\Operation\Post(
        path: '/api/libraries/scan-all',
        summary: 'Trigger scan for all libraries (admin)',
        description: 'Dispatches an asynchronous scan job for every library. Skips libraries already scanning.',
        responses: [
            new OA\Response(
                response: '202',
                description: 'Scans dispatched',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'dispatched' => new OA\Schema(description: 'Number of scans dispatched', type: 'integer'),
                                    'skipped' => new OA\Schema(description: 'Number of libraries skipped (already scanning)', type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/scan-all', name: 'scan_all', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:library:scan')]
    public function scanAll(): JsonResponse
    {
        return $this->successResponse(LibraryScanAllResource::from($this->dispatch(new ScanAllLibrariesCommand())), Response::HTTP_ACCEPTED);
    }

    /** Only administrators read the filesystem paths of libraries; members read the rest. */
    private function readsServerPaths(): bool
    {
        return $this->security?->isGranted('ROLE_ADMIN') === true;
    }

    /** A handler's exception reaches ExceptionSubscriber, which unwraps it to its 404, 409 or 422 response. */
    private function dispatch(object $message): mixed
    {
        return $this->commandBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
