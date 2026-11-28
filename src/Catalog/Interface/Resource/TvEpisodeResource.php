<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'name' => new OA\Schema(type: 'string'),
        'overview' => new OA\Schema(type: 'string', nullable: true),
        'stillUrl' => new OA\Schema(type: 'string', nullable: true),
        'airDate' => new OA\Schema(type: 'string', nullable: true),
        'seasonNumber' => new OA\Schema(type: 'integer', nullable: true),
        'episodeNumber' => new OA\Schema(type: 'integer', nullable: true),
        'seriesPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'seriesName' => new OA\Schema(type: 'string'),
        'seasonPublicId' => new OA\Schema(type: 'string', nullable: true, minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'absoluteNumber' => new OA\Schema(type: 'integer', nullable: true),
        'libraryPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'metadataSource' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchCandidatesResource::PROVIDERS),
        'watched' => new OA\Schema(type: 'boolean'),
        'progress' => new OA\Schema(type: 'number', nullable: true, minimum: 0, maximum: 1),
        'defaultVideoPublicId' => new OA\Schema(type: 'string', nullable: true, minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'videos' => new OA\Schema(type: 'array', items: new OA\Schema(ref: VideoVersionResource::class)),
    ],
    required: ['publicId', 'name', 'overview', 'stillUrl', 'airDate', 'seasonNumber', 'episodeNumber', 'seriesPublicId', 'seriesName', 'seasonPublicId', 'absoluteNumber', 'libraryPublicId', 'metadataSource', 'watched', 'progress', 'defaultVideoPublicId', 'videos'],
    component: 'TvEpisodeResource',
)]
final class TvEpisodeResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        $source['videos'] = VideoVersionResource::collection($source['videos']);

        return array_intersect_key($source, array_flip(['publicId', 'name', 'overview', 'stillUrl', 'airDate', 'seasonNumber', 'episodeNumber', 'seriesPublicId', 'seriesName', 'seasonPublicId', 'absoluteNumber', 'libraryPublicId', 'metadataSource', 'watched', 'progress', 'defaultVideoPublicId', 'videos']));
    }
}
