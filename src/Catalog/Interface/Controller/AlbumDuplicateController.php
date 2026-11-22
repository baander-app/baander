<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Spec as OA;
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

    #[OA\Operation\Get(
        path: '/api/admin/albums/duplicates',
        summary: 'List all duplicate album groups in the library',
        parameters: [
            new OA\Parameter(
                name: 'libraryId',
                in: 'query',
                description: 'Library ID to scan for duplicates',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of duplicate groups',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: DuplicateGroupResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden - admin only',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid library ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Library not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/duplicates', name: 'duplicates', methods: ['GET'])]
    #[CliCounterpart('app:album:duplicates')]
    public function listDuplicates(Request $request): JsonResponse
    {
        $libraryId = $request->query->get('libraryId');

        if (!is_string($libraryId) || $libraryId === '') {
            return $this->errorResponse($this->trans('errors.invalid_library_id'), 400);
        }

        try {
            $libraryPublicId = PublicId::fromString($libraryId);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'), 400);
        }

        $groups = $this->duplicateService->findDuplicatesByLibraryPublicId($libraryPublicId);
        if ($groups === null) {
            return $this->notFound();
        }

        return $this->successResponse(array_map(
            fn($group) => DuplicateGroupResource::from($group),
            $groups,
        ));
    }
}
