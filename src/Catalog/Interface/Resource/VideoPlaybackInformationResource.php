<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'qualityChoices' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'id' => new OA\Schema(type: 'string'),
                    'label' => new OA\Schema(type: 'string'),
                    'width' => new OA\Schema(type: 'integer'),
                    'height' => new OA\Schema(type: 'integer'),
                ],
            ),
        ),
        'audioTracks' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'index' => new OA\Schema(type: 'integer', nullable: true),
                    'language' => new OA\Schema(type: 'string'),
                    'title' => new OA\Schema(type: 'string'),
                    'codec' => new OA\Schema(type: 'string'),
                    'channels' => new OA\Schema(type: 'integer'),
                    'isDefault' => new OA\Schema(type: 'boolean'),
                ],
            ),
        ),
        'subtitleTracks' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'index' => new OA\Schema(type: 'integer'),
                    'language' => new OA\Schema(type: 'string'),
                    'title' => new OA\Schema(type: 'string'),
                    'codec' => new OA\Schema(type: 'string'),
                    'isDefault' => new OA\Schema(type: 'boolean'),
                    'isForced' => new OA\Schema(type: 'boolean'),
                    'isText' => new OA\Schema(type: 'boolean'),
                ],
            ),
        ),
        'chapters' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'start' => new OA\Schema(type: 'number'),
                    'end' => new OA\Schema(type: 'number'),
                    'title' => new OA\Schema(type: 'string'),
                ],
            ),
        ),
        'preview' => new OA\Schema(
            properties: [
                'state' => new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'ready', 'failed', 'unavailable'],
                ),
                'url' => new OA\Schema(type: 'string', nullable: true),
                'interval' => new OA\Schema(type: 'number'),
                'width' => new OA\Schema(type: 'integer'),
                'height' => new OA\Schema(type: 'integer'),
                'columns' => new OA\Schema(type: 'integer'),
                'count' => new OA\Schema(type: 'integer'),
            ],
        ),
    ],
    required: ['qualityChoices', 'audioTracks', 'subtitleTracks', 'chapters', 'preview'],
    component: 'VideoPlaybackInformationResource',
)]
final class VideoPlaybackInformationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'qualityChoices' => $source['qualityChoices'],
            'audioTracks' => $source['audioTracks'],
            'subtitleTracks' => $source['subtitleTracks'],
            'chapters' => $source['chapters'],
            'preview' => $source['preview'],
        ];
    }
}
