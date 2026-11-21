<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\Song\SongDeletePreview;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** The `data` of GET /api/admin/songs/{publicId}/delete-preview, and of `app:song:delete --dry-run --json`. */
#[OA\Schema(
    type: 'object',
    properties: [
        'song' => new OA\Schema(
            type: 'object',
            properties: [
                'id' => new OA\Schema(
                    type: 'string',
                    minLength: 21,
                    maxLength: 21,
                    pattern: '^[0-9a-zA-Z_-]{21}$',
                ),
                'title' => new OA\Schema(type: 'string'),
                'album' => new OA\Schema(
                    type: 'object',
                    nullable: true,
                    properties: [
                        'id' => new OA\Schema(
                            type: 'string',
                            minLength: 21,
                            maxLength: 21,
                            pattern: '^[0-9a-zA-Z_-]{21}$',
                        ),
                        'title' => new OA\Schema(type: 'string'),
                    ],
                ),
            ],
        ),
        'file' => new OA\Schema(
            type: 'object',
            properties: [
                'path' => new OA\Schema(type: 'string'),
                'size' => new OA\Schema(type: 'integer'),
            ],
        ),
        'affected' => new OA\Schema(
            type: 'object',
            properties: [
                'playlists' => new OA\Schema(type: 'integer'),
                'playlistNames' => new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
            ],
        ),
        'fileDeletion' => new OA\Schema(
            description: 'The check of the song file, when deleteFile is true',
            nullable: true,
            oneOf: [
                new OA\Schema(ref: FileDeletionPreviewResource::class),
            ],
        ),
    ],
    required: ['song', 'file', 'affected'],
    component: 'SongDeletePreviewResource',
)]
final class SongDeletePreviewResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert($source instanceof SongDeletePreview);

        return [
            'song' => [
                'id' => $source->publicId,
                'title' => $source->title,
                'album' => $source->album,
            ],
            'file' => [
                'path' => $source->path,
                'size' => $source->size,
            ],
            'affected' => [
                'playlists' => count($source->playlistNames),
                'playlistNames' => $source->playlistNames,
            ],
            'fileDeletion' => $source->fileDeletion !== null ? FileDeletionPreviewResource::from($source->fileDeletion) : null,
        ];
    }
}
