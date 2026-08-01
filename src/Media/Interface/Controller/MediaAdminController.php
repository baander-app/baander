<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\Port\MediaAdminPortInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
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

    #[OA\Get(
        path: '/api/admin/media/storage-stats',
        summary: 'Get image storage statistics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Storage statistics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'totalImages', type: 'integer'),
                            new OA\Property(property: 'totalSize', type: 'integer'),
                            new OA\Property(property: 'byType', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'type', type: 'string'),
                                    new OA\Property(property: 'count', type: 'integer'),
                                    new OA\Property(property: 'size', type: 'integer'),
                                ],
                            )),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/storage-stats', name: 'storage_stats', methods: ['GET'])]
    public function storageStats(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->getStorageStats());
    }

    #[OA\Post(
        path: '/api/admin/media/prune-missing',
        summary: 'Dispatch async job to prune image records whose files no longer exist on disk',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job dispatched',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'dispatched', type: 'boolean'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/prune-missing', name: 'prune_missing', methods: ['POST'])]
    public function pruneMissing(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->pruneMissingImages());
    }

    #[OA\Get(
        path: '/api/admin/media/missing-check',
        summary: 'Check how many images have missing files (dry-run)',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Missing images report',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'totalImages', type: 'integer'),
                            new OA\Property(property: 'missingCount', type: 'integer'),
                            new OA\Property(property: 'missingImages', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string'),
                                    new OA\Property(property: 'path', type: 'string'),
                                    new OA\Property(property: 'type', type: 'string'),
                                ],
                            )),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/missing-check', name: 'missing_check', methods: ['GET'])]
    public function missingCheck(): JsonResponse
    {
        return $this->successResponse($this->mediaAdmin->checkMissingImages());
    }
}
