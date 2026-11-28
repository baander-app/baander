<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(
            type: 'string',
            enum: ['song', 'video'],
        ),
        'publicId' => new OA\Schema(type: 'string'),
        'viewing' => new OA\Schema(
            ref: PlaybackViewingContextResource::class,
        ),
    ],
    required: ['kind', 'publicId'],
    component: 'PlaybackMediaResource',
)]
final class PlaybackMediaResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        $media = ['kind' => $source['kind'], 'publicId' => $source['publicId']];
        if (isset($source['viewing'])) {
            $media['viewing'] = PlaybackViewingContextResource::from($source['viewing']);
        }

        return $media;
    }
}
