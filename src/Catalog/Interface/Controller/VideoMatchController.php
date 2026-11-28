<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Catalog\Interface\Request\ApplyVideoMatchRequest;
use App\Catalog\Interface\Request\AssignVideoFileRequest;
use App\Catalog\Interface\Request\RematchVideoLibraryRequest;
use App\Catalog\Interface\Request\SetVideoOrderingRequest;
use App\Catalog\Interface\Resource\VideoLibraryRematchResource;
use App\Catalog\Interface\Resource\VideoMatchCandidatesResource;
use App\Catalog\Interface\Resource\VideoMatchChoicesResource;
use App\Catalog\Interface\Resource\VideoMatchReviewPageResource;
use App\Catalog\Interface\Request\LibraryProviderOrderRequest;
use App\Catalog\Interface\Resource\LibraryProviderOrderResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use App\Shared\Interface\Request\QueryParameters;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private LibraryProviderOrderService $orders,
        private VideoMatchAdminService $matches,
    ) {}

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

    #[OA\Operation\Get(
        path: '/api/admin/video-matches/reviews',
        summary: 'List video matching reviews and paused providers',
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'library', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reason', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(ref: VideoMatchReviewPageResource::class)],
                )),
            ),
            new OA\Response(response: 400, description: 'Invalid query parameter', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/reviews', name: 'reviews', methods: ['GET'])]
    #[CliCounterpart('app:video:match:reviews')]
    public function reviews(Request $request): JsonResponse
    {
        $page = $this->matches->review(
            limit: QueryParameters::integer($request->query, 'limit', 50, 1, 100),
            cursor: self::optionalQuery($request, 'cursor'),
            kind: self::optionalQuery($request, 'kind'),
            library: self::optionalQuery($request, 'library'),
            reason: self::optionalQuery($request, 'reason'),
        );

        return $this->successResponse(VideoMatchReviewPageResource::from($page));
    }

    #[OA\Operation\Get(
        path: '/api/admin/video-matches/{kind}/{publicId}/candidates',
        summary: 'Search metadata candidates or browse video edit choices',
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'view', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['search', 'choices'], default: 'search')),
            new OA\Parameter(name: 'collection', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'orderings', 'episodes', 'files'], default: 'all')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 1000000, default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(oneOf: [new OA\Schema(ref: VideoMatchCandidatesResource::class), new OA\Schema(ref: VideoMatchChoicesResource::class)])],
                )),
            ),
            new OA\Response(response: 400, description: 'Invalid query parameter', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/{kind}/{publicId}/candidates', name: 'candidates', methods: ['GET'])]
    #[CliCounterpart('app:video:match:search')]
    public function candidates(string $kind, string $publicId, Request $request): JsonResponse
    {
        $view = $request->query->getString('view', 'search');
        $candidates = $this->matches->candidates(
            $kind, $publicId, self::optionalQuery($request, 'q'), $view,
            $request->query->getString('collection', 'all'),
            $view === 'choices' ? $request->query->getInt('page', 1) : 1,
            $view === 'choices' ? $request->query->getInt('limit', 50) : 50,
        );

        return $this->successResponse(VideoMatchCandidatesResource::from($candidates));
    }

    #[OA\Operation\Post(
        path: '/api/admin/video-matches/{kind}/{publicId}/apply',
        summary: 'Apply an explicit provider match to a series or movie',
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApplyVideoMatchRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(type: 'object', properties: [
                        'kind' => new OA\Schema(type: 'string', enum: ['series', 'movie']),
                        'publicId' => new OA\Schema(type: 'string'),
                        'provider' => new OA\Schema(type: 'string', enum: ['tvdb', 'tmdb', 'local']),
                        'providerId' => new OA\Schema(type: 'string'),
                        'ordering' => new OA\Schema(type: 'string', nullable: true),
                    ], required: ['kind', 'publicId', 'provider', 'providerId', 'ordering'])],
                )),
            ),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/{kind}/{publicId}/apply', name: 'apply', methods: ['POST'])]
    #[CliCounterpart('app:video:match:apply')]
    public function apply(string $kind, string $publicId, #[MapRequestPayload] ApplyVideoMatchRequest $request): JsonResponse
    {
        return $this->successResponse($this->matches->rematchTitle($kind, $publicId, $request->provider, $request->candidateId));
    }

    #[OA\Operation\Get(
        path: '/api/admin/video-matches/libraries/{library}/rematch-preview',
        summary: 'Preview the titles and manual choices in a library rematch',
        parameters: [
            new OA\Parameter(name: 'library', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(ref: VideoLibraryRematchResource::class)],
                )),
            ),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/libraries/{library}/rematch-preview', name: 'library_rematch_preview', methods: ['GET'])]
    #[CliCounterpart('app:video:match:rematch')]
    public function libraryRematchPreview(string $library): JsonResponse
    {
        return $this->successResponse(VideoLibraryRematchResource::from($this->matches->previewLibraryRematch($library)));
    }

    #[OA\Operation\Post(
        path: '/api/admin/video-matches/libraries/{library}/rematch',
        summary: 'Confirm a library rematch and choose whether to replace manual matches',
        parameters: [
            new OA\Parameter(name: 'library', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType\Json(schema: new OA\Schema(ref: RematchVideoLibraryRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(ref: VideoLibraryRematchResource::class)],
                )),
            ),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/libraries/{library}/rematch', name: 'library_rematch', methods: ['POST'])]
    #[CliCounterpart('app:video:match:rematch')]
    public function rematchLibrary(string $library, #[MapRequestPayload] RematchVideoLibraryRequest $request): JsonResponse
    {
        $result = $this->matches->rematchLibrary($library, $request->confirmed, $request->force);

        return $this->successResponse(VideoLibraryRematchResource::from($result));
    }

    #[OA\Operation\Put(
        path: '/api/admin/video-matches/series/{publicId}/ordering',
        summary: 'Choose an ordering offered by the matched series provider',
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType\Json(schema: new OA\Schema(ref: SetVideoOrderingRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(type: 'object', properties: [
                        'publicId' => new OA\Schema(type: 'string'),
                        'ordering' => new OA\Schema(type: 'string'),
                    ], required: ['publicId', 'ordering'])],
                )),
            ),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/series/{publicId}/ordering', name: 'ordering', methods: ['PUT'])]
    #[CliCounterpart('app:video:match:order')]
    public function ordering(string $publicId, #[MapRequestPayload] SetVideoOrderingRequest $request): JsonResponse
    {
        return $this->successResponse($this->matches->setOrdering($publicId, $request->ordering));
    }

    #[OA\Operation\Put(
        path: '/api/admin/video-matches/files/{publicId}/assignment',
        summary: 'Assign a video file to episodes or an extra kind',
        parameters: [
            new OA\Parameter(name: 'publicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType\Json(schema: new OA\Schema(ref: AssignVideoFileRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video match operation result',
                content: new OA\MediaType\Json(schema: new OA\Schema(
                    type: 'object',
                    properties: ['data' => new OA\Schema(type: 'object', properties: [
                        'publicId' => new OA\Schema(type: 'string'),
                        'episodes' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
                        'extraKind' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchChoicesResource::EXTRA_KINDS),
                    ], required: ['publicId', 'episodes', 'extraKind'])],
                )),
            ),
            new OA\Response(response: 403, description: 'Administrator access required', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 404, description: 'Video match target not found', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 409, description: 'The match operation conflicts with the current state', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
            new OA\Response(response: 422, description: 'Invalid match input', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ValidationError::class))),
            new OA\Response(response: 503, description: 'The metadata provider is unavailable', content: new OA\MediaType\Json(schema: new OA\Schema(ref: ApiError::class))),
        ],
    )]
    #[Route('/files/{publicId}/assignment', name: 'assignment', methods: ['PUT'])]
    #[CliCounterpart('app:video:match:assign')]
    public function assignment(string $publicId, #[MapRequestPayload] AssignVideoFileRequest $request): JsonResponse
    {
        return $this->successResponse($this->matches->assignFile($publicId, $request->episodePublicIds, $request->extraKind));
    }

    private static function optionalQuery(Request $request, string $name): ?string
    {
        return $request->query->has($name) ? $request->query->getString($name) : null;
    }
}
