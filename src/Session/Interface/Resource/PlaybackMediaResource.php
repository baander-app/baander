<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackMediaResource',
    required: ['kind', 'publicId'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['song', 'video']),
        new OA\Property(property: 'publicId', type: 'string'),
    ],
)]
final class PlaybackMediaResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return ['kind' => $source['kind'], 'publicId' => $source['publicId']];
    }
}
