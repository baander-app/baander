<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MovieResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'title', description: 'Movie title', type: 'string'),
        new OA\Property(property: 'year', description: 'Release year', type: 'integer', nullable: true),
        new OA\Property(property: 'summary', description: 'Plot summary', type: 'string', nullable: true),
        new OA\Property(property: 'overview', description: 'TMDB overview', type: 'string', nullable: true),
        new OA\Property(property: 'tagline', description: 'Movie tagline', type: 'string', nullable: true),
        new OA\Property(property: 'posterUrl', description: 'Poster image URL', type: 'string', nullable: true),
        new OA\Property(property: 'backdropUrl', description: 'Backdrop image URL', type: 'string', nullable: true),
        new OA\Property(property: 'runtime', description: 'Runtime in minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'rating', description: 'TMDB vote average', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'originalLanguage', description: 'Original language code', type: 'string', nullable: true),
        new OA\Property(property: 'tmdbId', description: 'TMDB ID', type: 'integer', nullable: true),
        new OA\Property(property: 'imdbId', description: 'IMDB ID', type: 'string', nullable: true),
        new OA\Property(property: 'tmdbCollectionId', description: 'TMDB collection ID', type: 'integer', nullable: true),
        new OA\Property(property: 'collectionName', description: 'Collection name', type: 'string', nullable: true),
        new OA\Property(property: 'videoIds', description: 'Video public identifiers', type: 'array', items: new OA\Items(type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21)),
        new OA\Property(property: 'posterImage', description: 'Poster image data', type: 'object', nullable: true),
        new OA\Property(property: 'genres', description: 'Genre list', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'videos', description: 'Video variants', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Update timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class MovieResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
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
