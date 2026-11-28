<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'title' => new OA\Schema(description: 'Movie title', type: 'string'),
        'year' => new OA\Schema(
            description: 'Release year',
            type: 'integer',
            nullable: true,
        ),
        'summary' => new OA\Schema(
            description: 'Plot summary',
            type: 'string',
            nullable: true,
        ),
        'overview' => new OA\Schema(
            description: 'TMDB overview',
            type: 'string',
            nullable: true,
        ),
        'tagline' => new OA\Schema(
            description: 'Movie tagline',
            type: 'string',
            nullable: true,
        ),
        'posterUrl' => new OA\Schema(
            description: 'Poster image URL',
            type: 'string',
            nullable: true,
        ),
        'backdropUrl' => new OA\Schema(
            description: 'Backdrop image URL',
            type: 'string',
            nullable: true,
        ),
        'runtime' => new OA\Schema(
            description: 'Runtime in minutes',
            type: 'integer',
            nullable: true,
        ),
        'rating' => new OA\Schema(
            description: 'TMDB vote average',
            type: 'number',
            format: 'float',
            nullable: true,
        ),
        'originalLanguage' => new OA\Schema(
            description: 'Original language code',
            type: 'string',
            nullable: true,
        ),
        'tmdbId' => new OA\Schema(
            description: 'TMDB ID',
            type: 'integer',
            nullable: true,
        ),
        'imdbId' => new OA\Schema(
            description: 'IMDB ID',
            type: 'string',
            nullable: true,
        ),
        'tmdbCollectionId' => new OA\Schema(
            description: 'TMDB collection ID',
            type: 'integer',
            nullable: true,
        ),
        'collectionName' => new OA\Schema(
            description: 'Collection name',
            type: 'string',
            nullable: true,
        ),
        'videoIds' => new OA\Schema(
            description: 'Video public identifiers',
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                minLength: PublicId::LENGTH,
                maxLength: PublicId::LENGTH,
                pattern: PublicId::SCHEMA_PATTERN,
            ),
        ),
        'posterImage' => new OA\Schema(
            description: 'Poster image data',
            type: 'object',
            nullable: true,
        ),
        'genres' => new OA\Schema(
            description: 'Genre list',
            type: 'array',
            items: new OA\Schema(type: 'object'),
        ),
        'videos' => new OA\Schema(type: 'array', items: new OA\Schema(ref: VideoVersionResource::class)),
        'extras' => new OA\Schema(type: 'array', items: new OA\Schema(ref: VideoExtraResource::class)),
        'libraryPublicId' => new OA\Schema(type: 'string'),
        'metadataSource' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchCandidatesResource::PROVIDERS),
        'watched' => new OA\Schema(type: 'boolean'),
        'progress' => new OA\Schema(type: 'number', nullable: true, minimum: 0, maximum: 1),
        'defaultVideoPublicId' => new OA\Schema(type: 'string', nullable: true),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'title', 'videoIds', 'genres', 'videos', 'createdAt', 'updatedAt'],
    component: 'MovieResource',
)]
final class MovieResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        if (is_array($source)) {
            return self::fromRead($source);
        }
        assert($source instanceof Movie);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'title' => $source->getTitle(),
            'year' => $source->getYear(),
            'summary' => $source->getSummary(),
            'overview' => $source->getOverview(),
            'tagline' => $source->getTagline(),
            'posterUrl' => $source->getPosterUrl(),
            'backdropUrl' => $source->getBackdropUrl(),
            'runtime' => $source->getRuntime(),
            'rating' => $source->getRating(),
            'originalLanguage' => $source->getOriginalLanguage(),
            'tmdbId' => $source->getTmdbId(),
            'imdbId' => $source->getImdbId(),
            'tmdbCollectionId' => $source->getTmdbCollectionId(),
            'collectionName' => $source->getCollectionName(),
            'videoIds' => $source->getVideoPublicIds(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function fromRead(array $source): array
    {
        assert($source['movie'] instanceof Movie);
        $movie = $source['movie'];
        $videos = VideoVersionResource::collection($source['videos']);
        $data = self::fromWithPoster(
            $movie,
            $movie->getPosterUrl() === null ? null : ['url' => $movie->getPosterUrl()],
            videos: $videos,
        );
        $data['videoIds'] = array_column($videos, 'publicId');
        $data['extras'] = VideoExtraResource::collection($source['extras']);
        foreach (['libraryPublicId', 'metadataSource', 'watched', 'progress', 'defaultVideoPublicId'] as $field) {
            $data[$field] = $source[$field];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $posterImage
     * @param array<int, array<string, mixed>> $genres
     * @param array<int, array<string, mixed>> $videos
     * @return array<string, mixed>
     */
    public static function fromWithPoster(Movie $source, ?array $posterImage = null, array $genres = [], array $videos = []): array
    {
        $data = self::from($source);
        $data['posterImage'] = $posterImage;
        $data['genres'] = $genres;
        $data['videos'] = $videos;

        return $data;
    }
}
