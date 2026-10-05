<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\Port\ImageConversionPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Converter\BlurHashGenerator;
use App\Media\Interface\Resource\ImageResource;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Media', description: 'Media file and streaming endpoints')]
#[Route('/api/images', name: 'image_')]
final class ImageController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly ImagePortInterface $imageService,
        private readonly StoragePortInterface $storage,
        private readonly ImageConversionPortInterface $converter,
        private readonly BlurHashGenerator $blurHashGenerator,
        private readonly LoggerInterface $logger,
        private readonly CoWrapper $coWrapper,
        private readonly MediaReadScopeProviderInterface $scopes,
    ) {
    }

    #[OA\Get(
        path: '/api/images/{publicId}',
        summary: 'Get image metadata by public ID',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Image public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'aB3dE5fG7hJ9kL1mN3pQr'),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Image metadata',
                headers: [new OA\Header(header: 'Cache-Control', description: 'Private response; storage and caching prohibited', schema: new OA\Schema(type: 'string', example: 'private, no-store'))],
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: new Model(type: ImageResource::class))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Image not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        try {
            $pid = new \App\Shared\Domain\Model\PublicId($publicId);
        } catch (\InvalidArgumentException) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $image = $this->imageService->findVisibleByPublicId($pid, $this->scopes->current());

        if ($image === null) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $response = $this->successResponse(ImageResource::from($image));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[OA\Get(
        path: '/api/images/{publicId}/file',
        summary: 'Serve the image file binary data',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Image public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'aB3dE5fG7hJ9kL1mN3pQr'),
            new OA\Parameter(name: 'preset', description: 'Image size preset', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['thumb', 'small', 'medium', 'large'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Image file binary data',
                headers: [
                    new OA\Header(header: 'Content-Type', description: 'MIME type of the image (e.g. image/jpeg, image/webp)', schema: new OA\Schema(type: 'string')),
                    new OA\Header(header: 'Content-Length', description: 'File size in bytes', schema: new OA\Schema(type: 'integer')),
                    new OA\Header(header: 'Cache-Control', description: 'Private response; storage and caching prohibited', schema: new OA\Schema(type: 'string', example: 'private, no-store')),
                ],
            ),
            new OA\Response(response: '404', description: 'Image or image file not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/file', name: 'file', methods: ['GET'])]
    public function file(string $publicId, Request $request): Response
    {
        try {
            $pid = new \App\Shared\Domain\Model\PublicId($publicId);
        } catch (\InvalidArgumentException) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $image = $this->imageService->findVisibleByPublicId($pid, $this->scopes->current());

        if ($image === null) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $fullPath = $this->storage->resolve($image->getPath());

        if (!file_exists($fullPath)) {
            return $this->notFound($this->trans('errors.image_file_not_found', domain: 'media'));
        }

        $outputDir = dirname($fullPath);
        $extension = $image->getExtension();
        $sourcePath = $image->getPath();
        $sourceDirectory = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $sourceName = pathinfo($sourcePath, PATHINFO_FILENAME);
        $derivedBasePath = ($sourceDirectory === '.' ? '' : $sourceDirectory . '/') . $sourceName;

        // Support preset-based size selection (e.g., ?preset=thumb)
        $preset = $request->query->get('preset');
        if ($preset !== null && in_array($preset, ['thumb', 'small', 'medium', 'large'], true)) {
            $presetPath = $this->resolveDerivedPath($derivedBasePath . '_' . $preset . '.webp');
            if ($presetPath !== null && file_exists($presetPath)) {
                $fullPath = $presetPath;
            } elseif ($presetPath !== null) {
                // Fire-and-forget: generate preset in background, serve original now
                $this->coWrapper->go(function () use ($fullPath, $outputDir, $preset): void {
                    try {
                        $this->converter->convertPreset($fullPath, $outputDir, $preset);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to generate image preset', [
                            'preset' => $preset,
                            'path' => $fullPath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }
        } elseif (!in_array($extension, ['webp', 'gif'], true)) {
            // Unconditional WebP conversion for non-WebP, non-GIF sources
            $webpPath = $this->resolveDerivedPath($derivedBasePath . '.webp');
            if ($webpPath !== null && file_exists($webpPath)) {
                $fullPath = $webpPath;
            } elseif ($webpPath !== null) {
                // Fire-and-forget: generate WebP in background, serve original now
                $this->coWrapper->go(function () use ($fullPath, $outputDir): void {
                    try {
                        $this->converter->convertToWebp($fullPath, $outputDir);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to convert image to WebP', [
                            'path' => $fullPath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }
        }

        $response = new BinaryFileResponse($fullPath);
        $mimeType = mime_content_type($fullPath);
        $response->headers->set('Content-Type', $mimeType !== false ? $mimeType : 'application/octet-stream');
        $response->headers->set('Content-Length', (string) filesize($fullPath));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function resolveDerivedPath(string $relativePath): ?string
    {
        try {
            return $this->storage->resolve($relativePath);
        } catch (\RuntimeException) {
            return null;
        }
    }

    #[OA\Get(
        path: '/api/images/{publicId}/blurhash',
        summary: 'Get the blurhash representation of an image',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Image public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'aB3dE5fG7hJ9kL1mN3pQr'),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Blurhash string with dimensions',
                headers: [new OA\Header(header: 'Cache-Control', description: 'Private response; storage and caching prohibited', schema: new OA\Schema(type: 'string', example: 'private, no-store'))],
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'blurhash', description: 'Blurhash string, or null if not computed', type: 'string', example: 'LKO2:N%2Tw=w]~RBVZRi};RPxuwH', nullable: true),
                        new OA\Property(property: 'width', description: 'Original image width in pixels', type: 'integer', nullable: true),
                        new OA\Property(property: 'height', description: 'Original image height in pixels', type: 'integer', nullable: true),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Image not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/blurhash', name: 'blurhash', methods: ['GET'])]
    public function blurhash(string $publicId): JsonResponse
    {
        try {
            $pid = new \App\Shared\Domain\Model\PublicId($publicId);
        } catch (\InvalidArgumentException) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $scope = $this->scopes->current();
        $image = $this->imageService->findVisibleByPublicId($pid, $scope);

        if ($image === null) {
            return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
        }

        $hash = $image->getBlurhash();

        if ($hash === null) {
            $fullPath = $this->storage->resolve($image->getPath());

            if (file_exists($fullPath)) {
                $hash = $this->blurHashGenerator->generate($fullPath);

                if ($hash !== null) {
                    if (!$this->imageService->saveVisibleBlurhash($image->getId(), $hash, $scope)) {
                        return $this->notFound($this->trans('errors.image_not_found', domain: 'media'));
                    }

                    $this->logger->info('Generated and stored blurhash for image', [
                        'image_id' => $image->getId()->toString(),
                    ]);
                } else {
                    $this->logger->warning('Failed to generate blurhash for image', [
                        'image_id' => $image->getId()->toString(),
                        'path' => $image->getPath(),
                    ]);
                }
            }
        }

        $response = $this->successResponse([
            'blurhash' => $hash,
            'width' => $image->getWidth(),
            'height' => $image->getHeight(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
