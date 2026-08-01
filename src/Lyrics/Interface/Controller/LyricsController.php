<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Controller;

use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Lyrics\Application\Port\LyricsPortInterface;
use App\Lyrics\Interface\Request\ApplyLyricsRequest;
use App\Lyrics\Interface\Request\SearchLyricsRequest;
use App\Lyrics\Interface\Resource\LyricsResource;
use App\Lyrics\Interface\Resource\LrclibSearchResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Lyrics', description: 'Song lyrics retrieval, search, and management')]
#[Route('/api', name: 'lyrics_')]
final class LyricsController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly LyricsPortInterface $lyricsPort,
        private readonly SongPortInterface $songPort,
    ) {
    }

    /**
     * Get cached lyrics for a song.
     */
    #[OA\Get(
        path: '/api/songs/{publicId}/lyrics',
        summary: 'Get cached lyrics for a song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Lyrics for the song', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LyricsResource::class))],
            )),
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/songs/{publicId}/lyrics', name: 'song_lyrics', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $song = $this->resolveSong($publicId);

        if ($song === null) {
            return $this->notFound();
        }

        $lyrics = $this->lyricsPort->findBySongId($song->getId());

        if ($lyrics === null) {
            return $this->successResponse([]);
        }

        return $this->successResponse(LyricsResource::from($lyrics));
    }

    /**
     * Fetch lyrics from LRCLIB for a song and store locally.
     */
    #[OA\Post(
        path: '/api/songs/{publicId}/lyrics/fetch',
        summary: 'Fetch lyrics from LRCLIB for a song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Lyrics fetched and stored', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LyricsResource::class))],
            )),
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/songs/{publicId}/lyrics/fetch', name: 'song_lyrics_fetch', methods: ['POST'])]
    public function fetch(string $publicId): JsonResponse
    {
        $song = $this->resolveSong($publicId);

        if ($song === null) {
            return $this->notFound();
        }

        $lyrics = $this->lyricsPort->fetchAndStore($song->getId());

        if ($lyrics === null) {
            return $this->successResponse([]);
        }

        return $this->successResponse(LyricsResource::from($lyrics));
    }

    /**
     * Search LRCLIB for lyrics.
     */
    #[OA\Get(
        path: '/api/lyrics/search',
        summary: 'Search LRCLIB for lyrics',
        parameters: [
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Search results', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: LrclibSearchResource::class))),
                ],
            )),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/lyrics/search', name: 'search', methods: ['GET'])]
    public function search(#[MapQueryString] SearchLyricsRequest $request): JsonResponse
    {
        $results = $this->lyricsPort->searchLrclib($request->q);

        return $this->successResponse(LrclibSearchResource::collection($results));
    }

    /**
     * Apply a specific LRCLIB search result to a song.
     */
    #[OA\Post(
        path: '/api/lyrics/search/{resultId}/apply',
        summary: 'Apply an LRCLIB search result to a song',
        parameters: [
            new OA\Parameter(name: 'resultId', description: 'LRCLIB search result ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Lyrics applied to song', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: LyricsResource::class))],
            )),
            new OA\Response(response: '404', description: 'Song or lyrics result not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/lyrics/search/{resultId}/apply', name: 'apply', methods: ['POST'])]
    public function apply(int $resultId, #[MapRequestPayload] ApplyLyricsRequest $payload): JsonResponse
    {
        try {
            $publicId = PublicId::fromString($payload->songPublicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $song = $this->songPort->findByPublicId($publicId);

        if ($song === null) {
            return $this->notFound();
        }

        $lyrics = $this->lyricsPort->applySearchResult($resultId, $song->getId());

        if ($lyrics === null) {
            return $this->notFound();
        }

        return $this->successResponse(LyricsResource::from($lyrics));
    }

    private function resolveSong(string $publicId): ?Song
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        return $this->songPort->findByPublicId($resolvedPublicId);
    }
}
