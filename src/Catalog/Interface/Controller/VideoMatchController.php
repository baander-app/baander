<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Interface\Request\LibraryProviderOrderRequest;
use App\Catalog\Interface\Resource\LibraryProviderOrderResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/admin/video-matches', name: 'admin_video_matches_')]
#[IsGranted('ROLE_ADMIN')]
final readonly class VideoMatchController
{
    use ApiResponsesTrait;

    public function __construct(private LibraryProviderOrderService $orders) {}

    #[OA\Operation\Get(
        path: '/api/admin/video-matches/libraries/{library}/provider-order',
        summary: 'Read the metadata provider order of a TV or movie library',
        parameters: [new OA\Parameter(
            name: 'library',
            in: 'path',
            required: true,
            description: 'Library public ID or slug',
            schema: new OA\Schema(type: 'string'),
        )],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Library provider order',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(ref: LibraryProviderOrderResource::class)],
                )),
            ),
            new OA\Response(
                response: 403,
                description: 'Administrator access required',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 404,
                description: 'Library not found',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 422,
                description: 'Library is not a TV or movie library',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class)),
            ),
        ],
    )]
    #[Route('/libraries/{library}/provider-order', name: 'provider_order', methods: ['GET'])]
    #[CliCounterpart('app:video:providers:order')]
    public function providerOrder(string $library): JsonResponse
    {
        return $this->successResponse(LibraryProviderOrderResource::from($this->orders->getByIdentifier($library)));
    }

    #[OA\Operation\Put(
        path: '/api/admin/video-matches/libraries/{library}/provider-order',
        summary: 'Replace the metadata provider order of a TV or movie library',
        parameters: [new OA\Parameter(
            name: 'library',
            in: 'path',
            required: true,
            description: 'Library public ID or slug',
            schema: new OA\Schema(type: 'string'),
        )],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(schema: new OA\Schema(ref: LibraryProviderOrderRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated library provider order',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(ref: LibraryProviderOrderResource::class)],
                )),
            ),
            new OA\Response(
                response: 403,
                description: 'Administrator access required',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 404,
                description: 'Library not found',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class)),
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid provider order or library type',
                content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class)),
            ),
        ],
    )]
    #[Route('/libraries/{library}/provider-order', name: 'provider_order_update', methods: ['PUT'])]
    #[CliCounterpart('app:video:providers:order')]
    public function updateProviderOrder(string $library, #[MapRequestPayload] LibraryProviderOrderRequest $request): JsonResponse
    {
        return $this->successResponse(LibraryProviderOrderResource::from($this->orders->setByIdentifier($library, $request->providers)));
    }
}
