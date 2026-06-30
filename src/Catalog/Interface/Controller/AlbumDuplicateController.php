<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/albums', name: 'admin_albums_')]
final class AlbumDuplicateController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly AlbumDuplicatePortInterface $duplicateService,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/albums/duplicates',
        summary: 'List all duplicate album groups in the library',
        parameters: [
            new OA\Parameter(
                name: 'libraryId',
                description: 'Library ID to scan for duplicates',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of duplicate groups',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: DuplicateGroupResource::class))),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden - admin only', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Invalid library ID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/duplicates', name: 'duplicates', methods: ['GET'])]
    public function listDuplicates(Request $request): JsonResponse
    {
        $libraryId = $request->query->get('libraryId');

        if (!is_string($libraryId) || $libraryId === '') {
            return $this->errorResponse($this->trans('errors.invalid_library_id'), 400);
        }

        try {
            $libraryUuid = \App\Shared\Domain\Model\Uuid::fromString($libraryId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_uuid'), 400);
        }

        $groups = $this->duplicateService->findDuplicates($libraryUuid);

        return $this->successResponse(array_map(
            fn($group) => DuplicateGroupResource::from($group),
            $groups,
        ));
    }
}
