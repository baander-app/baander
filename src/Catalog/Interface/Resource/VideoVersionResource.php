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
        'width' => new OA\Schema(type: 'integer', nullable: true),
        'height' => new OA\Schema(type: 'integer', nullable: true),
        'duration' => new OA\Schema(type: 'number', nullable: true),
        'videoBitrate' => new OA\Schema(type: 'integer', nullable: true),
        'positionSeconds' => new OA\Schema(type: 'number', nullable: true),
        'lastPlayedAt' => new OA\Schema(type: 'string', nullable: true, format: 'date-time'),
    ],
    required: ['publicId', 'width', 'height', 'duration', 'videoBitrate', 'positionSeconds', 'lastPlayedAt'],
    component: 'VideoVersionResource',
)]
final class VideoVersionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'width', 'height', 'duration', 'videoBitrate', 'positionSeconds', 'lastPlayedAt']));
    }
}
