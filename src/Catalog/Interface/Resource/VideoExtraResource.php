<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'width' => new OA\Schema(type: 'integer', nullable: true),
        'height' => new OA\Schema(type: 'integer', nullable: true),
        'duration' => new OA\Schema(type: 'number', nullable: true),
        'videoBitrate' => new OA\Schema(type: 'integer', nullable: true),
        'positionSeconds' => new OA\Schema(type: 'number', nullable: true),
        'lastPlayedAt' => new OA\Schema(type: 'string', nullable: true, format: 'date-time'),
        'placementPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'videoPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'kind' => new OA\Schema(type: 'string', enum: [ExtraKind::class]),
    ],
    required: ['publicId', 'width', 'height', 'duration', 'videoBitrate', 'positionSeconds', 'lastPlayedAt', 'placementPublicId', 'videoPublicId', 'kind'],
    component: 'VideoExtraResource',
)]
final class VideoExtraResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'width', 'height', 'duration', 'videoBitrate', 'positionSeconds', 'lastPlayedAt', 'placementPublicId', 'videoPublicId', 'kind']));
    }
}
