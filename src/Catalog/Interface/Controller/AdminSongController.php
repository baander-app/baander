<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\Query\Song\GetSongDeletePreviewQuery;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Catalog\Interface\Resource\SongDeletePreviewResource;
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

#[OA\Tag(name: 'Admin - Song')]
#[Route('/api/admin/songs', name: 'admin_song_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSongController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/songs/{publicId}/delete-preview',
        summary: 'Preview what will be deleted when deleting a song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFile', description: 'Also check the song file as a delete with deleteFile would', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Delete preview data',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: new Model(type: SongDeletePreviewResource::class))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/delete-preview', name: 'delete_preview', methods: ['GET'])]
    #[CliCounterpart('app:song:delete')]
    public function deletePreview(string $publicId, Request $request): JsonResponse
    {
        $preview = $this->dispatch(new GetSongDeletePreviewQuery(
            publicId: $publicId,
            deleteFile: filter_var($request->query->get('deleteFile', 'false'), FILTER_VALIDATE_BOOLEAN),
        ));

        return $this->successResponse(SongDeletePreviewResource::from($preview));
    }

    #[OA\Delete(
        path: '/api/admin/songs/{publicId}',
        summary: 'Delete a song with optional file deletion',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFile', description: 'Also delete the audio file, inside the library root only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Song deleted; with deleteFile, whether the file was removed or left',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: new Model(type: CatalogDeletionResource::class))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'With deleteFile: a scan holds the library, or the server cannot write the song directory; nothing was deleted', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID, or with deleteFile a file outside the library root; nothing was deleted', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    #[CliCounterpart('app:song:delete')]
    public function delete(string $publicId, Request $request): JsonResponse
    {
        $result = $this->dispatch(new DeleteSongCommand(
            publicId: $publicId,
            deleteFile: filter_var($request->query->get('deleteFile', 'false'), FILTER_VALIDATE_BOOLEAN),
        ));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }

    /** A handler's exception reaches ExceptionSubscriber, which unwraps it to its 404, 409 or 422 response. */
    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
