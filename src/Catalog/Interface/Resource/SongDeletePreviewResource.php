<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\Song\SongDeletePreview;
use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The `data` of GET /api/admin/songs/{publicId}/delete-preview, and of `app:song:delete --dry-run --json`. */
#[OA\Schema(
    schema: 'SongDeletePreviewResource',
    properties: [
        new OA\Property(property: 'song', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'album', properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'title', type: 'string'),
            ], type: 'object', nullable: true),
        ], type: 'object'),
        new OA\Property(property: 'file', properties: [
            new OA\Property(property: 'path', type: 'string'),
            new OA\Property(property: 'size', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'affected', properties: [
            new OA\Property(property: 'playlists', type: 'integer'),
            new OA\Property(property: 'playlistNames', type: 'array', items: new OA\Items(type: 'string')),
        ], type: 'object'),
        new OA\Property(
            property: 'fileDeletion',
            description: 'The check of the song file, when deleteFile is true',
            nullable: true,
            oneOf: [new OA\Schema(ref: new Model(type: FileDeletionPreviewResource::class))],
        ),
    ],
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
