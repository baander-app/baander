<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'name' => new OA\Schema(type: 'string'),
        'overview' => new OA\Schema(type: 'string', nullable: true),
        'stillUrl' => new OA\Schema(type: 'string', nullable: true),
        'airDate' => new OA\Schema(type: 'string', nullable: true),
        'seasonNumber' => new OA\Schema(type: 'integer'),
        'episodeNumber' => new OA\Schema(type: 'integer'),
        'seriesPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'seriesName' => new OA\Schema(type: 'string'),
    ],
    required: ['publicId', 'name', 'overview', 'stillUrl', 'airDate', 'seasonNumber', 'episodeNumber', 'seriesPublicId', 'seriesName'],
    component: 'TvEpisodeResource',
)]
final class TvEpisodeResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'name', 'overview', 'stillUrl', 'airDate', 'seasonNumber', 'episodeNumber', 'seriesPublicId', 'seriesName']));
    }
}
