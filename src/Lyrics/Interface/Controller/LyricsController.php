<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Controller;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Lyrics\Application\Command\ApplyLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LyricsFetchResult;
use App\Lyrics\Application\Port\LyricsPortInterface;
use App\Lyrics\Application\Query\SearchLyricsQuery;
use App\Lyrics\Application\Service\LyricsSongResolver;
use App\Lyrics\Interface\Request\ApplyLyricsRequest;
use App\Lyrics\Interface\Request\SearchLyricsRequest;
use App\Lyrics\Interface\Resource\LyricsResource;
use App\Lyrics\Interface\Resource\LrclibSearchResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Lyrics', description: 'Song lyrics retrieval, search, and management')]
#[Route('/api', name: 'lyrics_')]
final class LyricsController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(
        private readonly LyricsPortInterface $lyricsPort,
        private readonly SongLookupInterface $songs,
        private readonly LibraryReadScopeProviderInterface $libraryReadScope,
        private readonly LyricsSongResolver $songResolver,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Get cached lyrics for a song.
     */
    #[OA\Operation\Get(
        path: '/api/songs/{publicId}/lyrics',
        summary: 'Get cached lyrics for a song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Song public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Lyrics for the song',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                oneOf: [
                                    new OA\Schema(ref: LyricsResource::class),
                                    new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(),
                                        maxItems: 0,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '404',
                description: 'Song not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/songs/{publicId}/lyrics', name: 'song_lyrics', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $songId = $this->resolveSongId($publicId);

        if ($songId === null) {
            return $this->notFound();
        }

        $lyrics = $this->lyricsPort->findBySongId($songId);

        if ($lyrics === null) {
            return $this->successResponse([]);
        }

        return $this->successResponse(LyricsResource::from($lyrics));
    }

    /**
     * Fetch lyrics from LRCLIB for a song and store locally.
     *
     * A song that has lyrics keeps them, and the response returns them without asking LRCLIB.
     * A song without an artist or a positive length is not looked up, and that cause, like a
     * request LRCLIB rejects, is a conflict whose `details.reason` names it.
     */
    #[OA\Operation\Post(
        path: '/api/songs/{publicId}/lyrics/fetch',
        summary: 'Fetch lyrics from LRCLIB for a song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Song public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The song\'s lyrics, just fetched or already stored; an empty array when LRCLIB has none',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                oneOf: [
                                    new OA\Schema(ref: LyricsResource::class),
                                    new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(),
                                        maxItems: 0,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '404',
                description: 'Song not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The fetch was not attempted or LRCLIB rejected it; `details.reason` is `song_has_no_artist` (no primary artist credit), `song_has_no_length` (length missing or not positive) or `lrclib_rejected`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid song public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'LRCLIB is unavailable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/songs/{publicId}/lyrics/fetch', name: 'song_lyrics_fetch', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:song:lyrics:fetch')]
    public function fetch(string $publicId): JsonResponse
    {
        $songId = $this->songResolver->songId($publicId, $this->libraryReadScope->current());

        $result = $this->dispatch(new FetchLyricsCommand($songId));
        assert($result instanceof LyricsFetchResult);
        $lyrics = $result->lyricsOrFail();

        return $this->successResponse($lyrics === null ? [] : LyricsResource::from($lyrics));
    }

    /**
     * Search LRCLIB for lyrics.
     *
     * The route requires the administrator role, as fetch and apply do: the whole server
     * sends LRCLIB one request every two seconds, and each search is one of them.
     */
    #[OA\Operation\Get(
        path: '/api/lyrics/search',
        summary: 'Search LRCLIB for lyrics',
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Search results; an empty array when nothing matches',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: LrclibSearchResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '409',
                description: 'LRCLIB rejected the search; `details.reason` is `lrclib_rejected`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The search query is blank',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'LRCLIB is unavailable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/lyrics/search', name: 'search', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:lyrics:search')]
    public function search(
        #[MapQueryString(validationFailedStatusCode: 422)]
        SearchLyricsRequest $request = new SearchLyricsRequest(),
    ): JsonResponse {
        $results = $this->dispatch(new SearchLyricsQuery($request->q));
        assert(is_array($results));

        return $this->successResponse(LrclibSearchResource::collection($results));
    }

    /**
     * Apply a specific LRCLIB search result to a song.
     */
    #[OA\Operation\Post(
        path: '/api/lyrics/search/{resultId}/apply',
        summary: 'Apply an LRCLIB search result to a song',
        parameters: [
            new OA\Parameter(
                name: 'resultId',
                in: 'path',
                description: 'LRCLIB search result ID',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ApplyLyricsRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Lyrics applied to song',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: LyricsResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '404',
                description: 'Song or lyrics result not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The song already has lyrics, which stay unchanged (`details.reason` is `song_has_lyrics`), or LRCLIB rejected the request (`lrclib_rejected`)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid song public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'LRCLIB is unavailable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/lyrics/search/{resultId}/apply', name: 'apply', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:lyrics:apply')]
    public function apply(int $resultId, #[MapRequestPayload] ApplyLyricsRequest $payload): JsonResponse
    {
        $songId = $this->songResolver->songId($payload->songPublicId, $this->libraryReadScope->current());

        return $this->successResponse(LyricsResource::from($this->dispatch(new ApplyLyricsCommand($resultId, $songId))));
    }

    private function resolveSongId(string $publicId): ?Uuid
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        return $this->songs->findVisibleSongId($resolvedPublicId, $this->libraryReadScope->current());
    }
}
