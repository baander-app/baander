<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VideoPlaybackInformationResource',
    properties: [
        new OA\Property(
            property: 'audioTracks',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'index', type: 'integer', nullable: true),
                new OA\Property(property: 'language', type: 'string'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'codec', type: 'string'),
                new OA\Property(property: 'channels', type: 'integer'),
                new OA\Property(property: 'isDefault', type: 'boolean'),
            ]),
        ),
        new OA\Property(
            property: 'subtitleTracks',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'index', type: 'integer'),
                new OA\Property(property: 'language', type: 'string'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'codec', type: 'string'),
                new OA\Property(property: 'isDefault', type: 'boolean'),
                new OA\Property(property: 'isForced', type: 'boolean'),
                new OA\Property(property: 'isText', type: 'boolean'),
            ]),
        ),
        new OA\Property(
            property: 'chapters',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'start', type: 'number'),
                new OA\Property(property: 'end', type: 'number'),
                new OA\Property(property: 'title', type: 'string'),
            ]),
        ),
        new OA\Property(
            property: 'preview',
            properties: [
                new OA\Property(
                    property: 'state',
                    type: 'string',
                    enum: ['pending', 'ready', 'failed', 'unavailable'],
                ),
                new OA\Property(property: 'url', type: 'string', nullable: true),
                new OA\Property(property: 'interval', type: 'number'),
                new OA\Property(property: 'width', type: 'integer'),
                new OA\Property(property: 'height', type: 'integer'),
                new OA\Property(property: 'columns', type: 'integer'),
                new OA\Property(property: 'count', type: 'integer'),
            ],
        ),
    ],
)]
final class VideoPlaybackInformationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'audioTracks' => $source['audioTracks'],
            'subtitleTracks' => $source['subtitleTracks'],
            'chapters' => $source['chapters'],
            'preview' => $source['preview'],
        ];
    }
}
