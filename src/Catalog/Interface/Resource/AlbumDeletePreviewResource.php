<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\Album\AlbumDeletePreview;
use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The `data` of GET /api/admin/albums/{publicId}/delete-preview, and of `app:album:delete --dry-run --json`. */
#[OA\Schema(
    schema: 'AlbumDeletePreviewResource',
    properties: [
        new OA\Property(property: 'album', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'songCount', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'files', properties: [
            new OA\Property(property: 'count', type: 'integer'),
            new OA\Property(property: 'totalSize', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'coverImage', properties: [
            new OA\Property(property: 'id', type: 'string'),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'affected', properties: [
            new OA\Property(property: 'playlists', type: 'integer'),
            new OA\Property(property: 'playlistNames', type: 'array', items: new OA\Items(type: 'string')),
        ], type: 'object'),
        new OA\Property(
            property: 'fileDeletion',
            description: 'The check of every song file, when deleteFiles is true',
            nullable: true,
            oneOf: [new OA\Schema(ref: new Model(type: FileDeletionPreviewResource::class))],
        ),
    ],
)]
final class AlbumDeletePreviewResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert($source instanceof AlbumDeletePreview);

        return [
            'album' => [
                'id' => $source->publicId,
                'title' => $source->title,
                'songCount' => $source->songCount,
            ],
            'files' => [
                'count' => $source->songCount,
                'totalSize' => $source->totalSize,
            ],
            'coverImage' => $source->coverImageId !== null ? ['id' => $source->coverImageId] : null,
            'affected' => [
                'playlists' => count($source->playlistNames),
                'playlistNames' => $source->playlistNames,
            ],
            'fileDeletion' => $source->fileDeletion !== null ? FileDeletionPreviewResource::from($source->fileDeletion) : null,
        ];
    }
}
