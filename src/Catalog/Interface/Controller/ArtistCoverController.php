<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\RemoveCoverCommand;
use App\Catalog\Application\Command\Cover\SetCoverCommand;
use App\Catalog\Interface\Resource\CoverImageResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Artist Cover')]
#[Route('/api/artists/{publicId}/cover', name: 'artist_cover_')]
#[IsGranted('ROLE_ADMIN')]
final class ArtistCoverController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Post(
        path: '/api/artists/{publicId}/cover',
        summary: 'Upload a cover image for an artist',
        requestBody: new OA\RequestBody(
            description: 'Cover image file (multipart form upload with field name "cover")',
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['cover'],
                    properties: [
                        new OA\Property(property: 'cover', description: 'Image file (jpeg, png, or webp, max 10 MB)', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Cover image uploaded successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'size', type: 'integer'), new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error (invalid public ID, missing file, oversized file, or unsupported type)', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'upload', methods: ['POST'])]
    #[CliCounterpart('app:artist:cover:set')]
    public function upload(string $publicId, Request $request): JsonResponse
    {
        $file = $request->files->get('cover');
        if (!$file instanceof UploadedFile) {
            throw new InvalidInputException('No file uploaded.');
        }

        $cover = $this->dispatch(new SetCoverCommand(CoverOwner::Artist, $publicId, $file->getPathname()));

        return $this->successResponse(CoverImageResource::from($cover));
    }

    #[OA\Delete(
        path: '/api/artists/{publicId}/cover',
        summary: 'Delete the cover image from an artist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Cover image deleted successfully'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Artist or cover image not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[CliCounterpart('app:artist:cover:remove')]
    public function delete(string $publicId): JsonResponse
    {
        $this->dispatch(new RemoveCoverCommand(CoverOwner::Artist, $publicId));

        return $this->noContent();
    }

    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
