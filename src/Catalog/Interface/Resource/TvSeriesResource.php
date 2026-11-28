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
        'posterUrl' => new OA\Schema(type: 'string', nullable: true),
        'libraryPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'metadataSource' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchCandidatesResource::PROVIDERS),
        'seasons' => new OA\Schema(type: 'array', items: new OA\Schema(ref: TvSeasonResource::class)),
        'unseasonedCount' => new OA\Schema(type: 'integer'),
        'extras' => new OA\Schema(type: 'array', items: new OA\Schema(ref: VideoExtraResource::class)),
    ],
    required: ['publicId', 'name', 'overview', 'posterUrl', 'libraryPublicId', 'metadataSource'],
    component: 'TvSeriesResource',
)]
final class TvSeriesResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        if (isset($source['seasons'])) {
            $source['seasons'] = TvSeasonResource::collection($source['seasons']);
        }
        if (isset($source['extras'])) {
            $source['extras'] = VideoExtraResource::collection($source['extras']);
        }

        return array_intersect_key($source, array_flip(['publicId', 'name', 'overview', 'posterUrl', 'libraryPublicId', 'metadataSource', 'seasons', 'unseasonedCount', 'extras']));
    }
}
