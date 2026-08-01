<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MovieResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Movie UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'title', type: 'string', description: 'Movie title'),
        new OA\Property(property: 'year', type: 'integer', nullable: true, description: 'Release year'),
        new OA\Property(property: 'summary', type: 'string', nullable: true, description: 'Plot summary'),
        new OA\Property(property: 'overview', type: 'string', nullable: true, description: 'TMDB overview'),
        new OA\Property(property: 'tagline', type: 'string', nullable: true, description: 'Movie tagline'),
        new OA\Property(property: 'posterUrl', type: 'string', nullable: true, description: 'Poster image URL'),
        new OA\Property(property: 'backdropUrl', type: 'string', nullable: true, description: 'Backdrop image URL'),
        new OA\Property(property: 'runtime', type: 'integer', nullable: true, description: 'Runtime in minutes'),
        new OA\Property(property: 'rating', type: 'float', nullable: true, description: 'TMDB vote average'),
        new OA\Property(property: 'originalLanguage', type: 'string', nullable: true, description: 'Original language code'),
        new OA\Property(property: 'tmdbId', type: 'integer', nullable: true, description: 'TMDB ID'),
        new OA\Property(property: 'imdbId', type: 'string', nullable: true, description: 'IMDB ID'),
        new OA\Property(property: 'tmdbCollectionId', type: 'integer', nullable: true, description: 'TMDB collection ID'),
        new OA\Property(property: 'collectionName', type: 'string', nullable: true, description: 'Collection name'),
        new OA\Property(property: 'videoIds', type: 'array', items: new OA\Items(type: 'string'), description: 'Video UUIDs'),
        new OA\Property(property: 'posterImage', type: 'object', nullable: true, description: 'Poster image data'),
        new OA\Property(property: 'genres', type: 'array', items: new OA\Items(type: 'object'), description: 'Genre list'),
        new OA\Property(property: 'videos', type: 'array', items: new OA\Items(type: 'object'), description: 'Video variants'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Update timestamp'),
    ],
)]
final class MovieResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Movie);

        return [
            'uuid' => $source->getId()->toString(),
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
            'videoIds' => $source->getVideoIds(),
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
