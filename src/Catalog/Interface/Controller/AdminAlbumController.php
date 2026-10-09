<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Query\Album\GetAlbumDeletePreviewQuery;
use App\Catalog\Interface\Resource\AlbumDeletePreviewResource;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Admin - Album')]
#[Route('/api/admin/albums', name: 'admin_album_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAlbumController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/albums/{publicId}/delete-preview',
        summary: 'Preview what will be deleted when deleting an album',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFiles', description: 'Also check each song file as a delete with deleteFiles would', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Delete preview data',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: new Model(type: AlbumDeletePreviewResource::class))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/delete-preview', name: 'delete_preview', methods: ['GET'])]
    #[CliCounterpart('app:album:delete')]
    public function deletePreview(string $publicId, Request $request): JsonResponse
    {
        $preview = $this->dispatch(new GetAlbumDeletePreviewQuery(
            publicId: $publicId,
            deleteFiles: filter_var($request->query->get('deleteFiles', 'false'), FILTER_VALIDATE_BOOLEAN),
        ));

        return $this->successResponse(AlbumDeletePreviewResource::from($preview));
    }

    #[OA\Delete(
        path: '/api/admin/albums/{publicId}',
        summary: 'Delete an album with optional file deletion',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFiles', description: 'Also delete the song audio files, inside the library root only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'deleteCover', description: 'Whether to delete cover image (default: true)', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Album deleted; with deleteFiles, the files removed and left',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: new Model(type: CatalogDeletionResource::class))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'With deleteFiles: a scan holds the library, or the server cannot write a song directory; nothing was deleted', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID, or with deleteFiles a song file outside the library root; nothing was deleted', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    #[CliCounterpart('app:album:delete')]
    public function delete(string $publicId, Request $request): JsonResponse
    {
        $result = $this->dispatch(new DeleteAlbumCommand(
            publicId: $publicId,
            deleteFiles: filter_var($request->query->get('deleteFiles', 'false'), FILTER_VALIDATE_BOOLEAN),
            deleteCover: filter_var($request->query->get('deleteCover', 'true'), FILTER_VALIDATE_BOOLEAN),
        ));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }

    /** A handler's exception reaches ExceptionSubscriber, which unwraps it to its 404, 409 or 422 response. */
    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
