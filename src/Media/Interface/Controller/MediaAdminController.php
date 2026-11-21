<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\Port\MediaAdminPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/media', name: 'admin_media_')]
final class MediaAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MediaAdminPortInterface $mediaAdmin,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/media/storage-stats',
        summary: 'Get image storage statistics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Storage statistics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'totalImages' => new OA\Schema(type: 'integer'),
                                    'totalSize' => new OA\Schema(type: 'integer'),
                                    'byType' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'type' => new OA\Schema(type: 'string'),
                                                'count' => new OA\Schema(type: 'integer'),
                                                'size' => new OA\Schema(type: 'integer'),
                                            ],
                                        ),
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/storage-stats', name: 'storage_stats', methods: ['GET'])]
    #[CliCounterpart('app:image:stats')]
    public function storageStats(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->getStorageStats());
    }

    #[OA\Operation\Post(
        path: '/api/admin/media/prune-missing',
        summary: 'Dispatch async job to prune image records whose files no longer exist on disk',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job dispatched',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'dispatched' => new OA\Schema(type: 'boolean'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/prune-missing', name: 'prune_missing', methods: ['POST'])]
    #[CliCounterpart('app:image:prune-missing')]
    public function pruneMissing(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->pruneMissingImages());
    }

    #[OA\Operation\Get(
        path: '/api/admin/media/missing-check',
        summary: 'Check how many images have missing files (dry-run)',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Missing images report',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'totalImages' => new OA\Schema(type: 'integer'),
                                    'missingCount' => new OA\Schema(type: 'integer'),
                                    'missingImages' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'publicId' => new OA\Schema(
                                                    type: 'string',
                                                    minLength: PublicId::LENGTH,
                                                    maxLength: PublicId::LENGTH,
                                                    pattern: PublicId::SCHEMA_PATTERN,
                                                ),
                                                'path' => new OA\Schema(type: 'string'),
                                                'type' => new OA\Schema(type: 'string'),
                                            ],
                                        ),
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/missing-check', name: 'missing_check', methods: ['GET'])]
    #[CliCounterpart('app:image:prune-missing')]
    public function missingCheck(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->checkMissingImages());
    }
}
