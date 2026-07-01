<?php

declare(strict_types=1);

namespace App\Library\Interface\Controller;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\PathValidator;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Application\Query\LibraryStatsQueryPort;
use App\Library\Domain\Model\Library;
use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Request\CreateLibraryRequest;
use App\Library\Interface\Request\UpdateLibraryRequest;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Library', description: 'Media library management endpoints')]
#[Route('/api/libraries', name: 'library_')]
final class LibraryController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly LibraryPortInterface $libraryService,
        private readonly LibraryStatsQueryPort $statsQuery,
        private readonly PathValidator $pathValidator,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/libraries',
        summary: 'List all libraries',
        parameters: [
            new OA\Parameter(name: 'type', description: 'Filter by library type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: LibraryResource::class)))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Invalid type filter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $typeFilter = $request->query->get('type');

        if ($typeFilter !== null) {
            try {
                $type = LibraryType::from($typeFilter);
                $libraries = $this->libraryService->findByType($type);
            } catch (\ValueError) {
                return $this->errorResponse($this->trans('errors.invalid_type', domain: 'library'), Response::HTTP_BAD_REQUEST);
            }
        } else {
            $libraries = $this->libraryService->findAllOrdered();
        }

        return $this->successResponse(LibraryResource::collection($libraries));
    }

    #[OA\Post(
        path: '/api/libraries',
        summary: 'Create a new library',
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(
                required: ['name', 'path', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'sortOrder', type: 'integer'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                ],
            ))),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LibraryResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Bad request', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Slug already exists', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'store', methods: ['POST'])]
    public function store(#[MapRequestPayload] CreateLibraryRequest $payload): JsonResponse
    {
        try {
            $libraryType = LibraryType::from($payload->type);
        } catch (\ValueError) {
            return $this->errorResponse(
                $this->trans('errors.invalid_type_with_allowed', ['{type}' => $payload->type, '{allowed}' => implode(', ', array_column(LibraryType::cases(), 'value'))], 'library'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $filesystemType = FilesystemType::from($payload->filesystemType);
        } catch (\ValueError) {
            return $this->errorResponse(
                sprintf('Invalid filesystem type "%s". Allowed: %s', $payload->filesystemType, implode(', ', array_column(FilesystemType::cases(), 'value'))),
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $libraryPath = new LibraryPath($payload->path);
            $librarySlug = $payload->slug !== null ? new LibrarySlug($payload->slug) : LibrarySlug::fromName($payload->name);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->libraryService->findBySlug($librarySlug);
        if ($existing !== null) {
            return $this->errorResponse($this->trans('errors.slug_exists', domain: 'library'), Response::HTTP_CONFLICT);
        }

        $library = Library::create(
            $payload->name,
            $librarySlug,
            $libraryPath,
            $libraryType,
            $filesystemType,
            $payload->sortOrder,
        );

        $this->libraryService->save($library);

        return $this->successResponse(LibraryResource::from($library), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/libraries/{id}',
        summary: 'Get a single library',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Library UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LibraryResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $uuid = $this->parseUuid($id);
        if ($uuid === null) {
            return $this->errorResponse($this->trans('errors.invalid_id', domain: 'library'), Response::HTTP_BAD_REQUEST);
        }

        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'library'));
        }

        return $this->successResponse(LibraryResource::from($library));
    }

    #[OA\Patch(
        path: '/api/libraries/{id}',
        summary: 'Update a library',
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'sortOrder', type: 'integer', nullable: true),
                ],
            ))),
        parameters: [
            new OA\Parameter(name: 'id', description: 'Library UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LibraryResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Bad request', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(#[MapRequestPayload] UpdateLibraryRequest $payload, string $id): JsonResponse
    {
        $uuid = $this->parseUuid($id);
        if ($uuid === null) {
            return $this->errorResponse($this->trans('errors.invalid_id', domain: 'library'), Response::HTTP_BAD_REQUEST);
        }

        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'library'));
        }

        try {
            $library->updateMetadata(
                name: $payload->name,
                sortOrder: $payload->sortOrder,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $this->libraryService->save($library);

        return $this->successResponse(LibraryResource::from($library));
    }

    #[OA\Delete(
        path: '/api/libraries/{id}',
        summary: 'Delete a library',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Library UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', example: null, nullable: true)], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function destroy(string $id): JsonResponse
    {
        $uuid = $this->parseUuid($id);
        if ($uuid === null) {
            return $this->errorResponse($this->trans('errors.invalid_id', domain: 'library'), Response::HTTP_BAD_REQUEST);
        }

        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'library'));
        }

        $this->libraryService->delete($library);

        return $this->successResponse(null);
    }

    #[OA\Post(
        path: '/api/libraries/{id}/scan',
        summary: 'Trigger a library scan',
        description: 'Dispatches an asynchronous scan job. The scan runs in the background and progress is reported via SSE.',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Library UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '202', description: 'Scan dispatched', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LibraryResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Scan already in progress', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/scan', name: 'scan', methods: ['POST'])]
    public function scan(string $id, Request $request): JsonResponse
    {
        $uuid = $this->parseUuid($id);
        if ($uuid === null) {
            return $this->errorResponse($this->trans('errors.invalid_id', domain: 'library'), Response::HTTP_BAD_REQUEST);
        }

        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'library'));
        }

        if ($library->getDiscoveryStatus() === 'scanning') {
            return $this->errorResponse(
                $this->trans('errors.scan_in_progress', domain: 'library'),
                Response::HTTP_CONFLICT,
            );
        }

        // Read from payload so it works for both JSON bodies (axios) and form data.
        $rescan = $request->getPayload()->getBoolean('rescan', false);

        $library->markDiscoveryStarted();
        $this->libraryService->save($library);

        $this->commandBus->dispatch(new ScanLibraryCommand(
            librarySlug: $library->getSlug(),
            rescan: $rescan,
        ));

        return $this->successResponse(LibraryResource::from($library), Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/libraries/{id}/stats',
        summary: 'Get library statistics',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Library UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'songs', type: 'integer', example: 1234),
                        new OA\Property(property: 'albums', type: 'integer', example: 100),
                        new OA\Property(property: 'artists', type: 'integer', example: 50),
                        new OA\Property(property: 'genres', type: 'integer', example: 20),
                        new OA\Property(property: 'totalSize', type: 'integer', description: 'Total file size in bytes', example: 53687091200),
                        new OA\Property(property: 'totalDuration', type: 'number', description: 'Total duration in seconds', example: 259200.5),
                    ], type: 'object'),
                ], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/stats', name: 'stats', methods: ['GET'])]
    public function stats(string $id): JsonResponse
    {
        $uuid = $this->parseUuid($id);
        if ($uuid === null) {
            return $this->errorResponse($this->trans('errors.invalid_id', domain: 'library'), Response::HTTP_BAD_REQUEST);
        }

        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            return $this->notFound($this->trans('errors.not_found', domain: 'library'));
        }

        $stats = $this->statsQuery->getStatsForLibrary($uuid);

        return $this->successResponse($stats);
    }

    #[OA\Post(
        path: '/api/libraries/validate-path',
        summary: 'Validate a library path',
        description: 'Checks whether a filesystem path exists and is readable. Use before creating a library.',
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(
                required: ['path'],
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: '/mnt/media/music'),
                ],
            ))),
        responses: [
            new OA\Response(response: '200', description: 'Validation result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'valid', type: 'boolean'),
                        new OA\Property(property: 'error', type: 'string', nullable: true),
                        new OA\Property(property: 'resolvedPath', type: 'string', nullable: true),
                        new OA\Property(property: 'exists', type: 'boolean'),
                        new OA\Property(property: 'readable', type: 'boolean'),
                    ], type: 'object'),
                ], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/validate-path', name: 'validate_path', methods: ['POST'])]
    public function validatePath(Request $request): JsonResponse
    {
        $path = $request->getPayload()->get('path');

        if (!is_string($path) || trim($path) === '') {
            return $this->errorResponse('Path is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $libraryPath = new LibraryPath($path);
        } catch (\InvalidArgumentException $e) {
            return $this->successResponse([
                'valid' => false,
                'error' => $e->getMessage(),
                'resolvedPath' => null,
                'exists' => false,
                'readable' => false,
            ]);
        }

        $result = $this->pathValidator->validate($libraryPath);

        return $this->successResponse([
            'valid' => $result->valid,
            'error' => $result->error,
            'resolvedPath' => $result->resolvedPath,
            'exists' => $result->exists,
            'readable' => $result->readable,
        ]);
    }

    #[OA\Post(
        path: '/api/libraries/scan-all',
        summary: 'Trigger scan for all libraries',
        description: 'Dispatches an asynchronous scan job for every library. Skips libraries already scanning.',
        responses: [
            new OA\Response(response: '202', description: 'Scans dispatched', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'dispatched', type: 'integer', description: 'Number of scans dispatched'),
                        new OA\Property(property: 'skipped', type: 'integer', description: 'Number of libraries skipped (already scanning)'),
                    ], type: 'object'),
                ], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/scan-all', name: 'scan_all', methods: ['POST'])]
    public function scanAll(): JsonResponse
    {
        $libraries = $this->libraryService->findAllOrdered();
        $dispatched = 0;
        $skipped = 0;

        foreach ($libraries as $library) {
            if ($library->getDiscoveryStatus() === 'scanning') {
                $skipped++;
                continue;
            }

            $library->markDiscoveryStarted();
            $this->libraryService->save($library);

            $this->commandBus->dispatch(new ScanLibraryCommand(
                librarySlug: $library->getSlug(),
            ));

            $dispatched++;
        }

        return $this->successResponse([
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ], Response::HTTP_ACCEPTED);
    }

    private function parseUuid(string $id): ?\App\Shared\Domain\Model\Uuid
    {
        try {
            return \App\Shared\Domain\Model\Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
