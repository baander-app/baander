<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Interface\Request\UploadCoverRequest;
use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Artist Cover')]
#[Route('/api/artists/{publicId}/cover', name: 'artist_cover_')]
#[IsGranted('ROLE_ADMIN')]
final class ArtistCoverController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly ArtistPortInterface $artistService,
        private readonly ImagePortInterface $imagePort,
        private readonly StoragePortInterface $storage,
        private readonly MimeDetectorPortInterface $mimeDetector,
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
            new OA\Response(response: '422', description: 'Validation error (missing file, oversized file, or unsupported type)', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(string $publicId, Request $request): JsonResponse
    {
        $resolvedPublicId = $this->resolvePublicId($publicId);
        if ($resolvedPublicId === null) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $artist = $this->artistService->findByPublicId($resolvedPublicId);
        if ($artist === null) {
            return $this->notFound();
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('cover');
        if ($file === null) {
            return $this->errorResponse('No file uploaded.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validation = new UploadCoverRequest($file, $this->mimeDetector);
        if (!$validation->validate()) {
            return $this->errorResponse(
                $validation->getError()->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $contents = $file->getContent();
        $dimensions = @getimagesizefromstring($contents);
        $width = $dimensions[0] ?? 0;
        $height = $dimensions[1] ?? 0;
        $mimeType = $validation->getMimeType();

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $relativePath = 'images/artist/' . $artist->getId()->toString() . '.' . $extension;
        $storedFile = $this->storage->storeFromBytes($contents, $relativePath);

        // Delete old cover if it exists
        if ($artist->getCoverImageId() !== null) {
            $oldImage = $this->imagePort->findByUuid($artist->getCoverImageId());
            if ($oldImage !== null) {
                $this->storage->delete($oldImage->getPath());
                $this->storage->deleteDerived($oldImage->getPath(), $oldImage->getExtension());
                $this->imagePort->delete($oldImage);
            }
        }

        $image = Image::create(
            path: $storedFile->getPath(),
            extension: $extension,
            mimeType: $mimeType,
            size: $storedFile->getSize(),
            width: $width,
            height: $height,
            imageableType: 'artist',
            artistId: $artist->getId(),
        );
        $this->imagePort->save($image);

        $artist->setCoverImage($image->getId());
        $this->artistService->save($artist);

        return $this->successResponse([
            'publicId' => $image->getPublicId()->toString(),
            'url'      => '/api/images/' . $image->getPublicId()->toString() . '/file',
            'size'     => $image->getSize(),
            'width'    => $image->getWidth(),
            'height'   => $image->getHeight(),
        ]);
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
        ],
    )]
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function delete(string $publicId): JsonResponse
    {
        $resolvedPublicId = $this->resolvePublicId($publicId);
        if ($resolvedPublicId === null) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $artist = $this->artistService->findByPublicId($resolvedPublicId);
        if ($artist === null) {
            return $this->notFound();
        }

        if ($artist->getCoverImageId() === null) {
            return $this->notFound();
        }

        $image = $this->imagePort->findByUuid($artist->getCoverImageId());
        if ($image !== null) {
            $this->storage->delete($image->getPath());
            $this->storage->deleteDerived($image->getPath(), $image->getExtension());
            $this->imagePort->delete($image);
        }

        $artist->setCoverImage(null);
        $this->artistService->save($artist);

        return $this->noContent();
    }

    private function resolvePublicId(string $publicId): ?PublicId
    {
        try {
            return PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }
    }
}
