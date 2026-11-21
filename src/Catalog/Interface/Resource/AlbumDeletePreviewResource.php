<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\Album\AlbumDeletePreview;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** The `data` of GET /api/admin/albums/{publicId}/delete-preview, and of `app:album:delete --dry-run --json`. */
#[OA\Schema(
    type: 'object',
    properties: [
        'album' => new OA\Schema(
            type: 'object',
            properties: [
                'id' => new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
                'title' => new OA\Schema(type: 'string'),
                'songCount' => new OA\Schema(type: 'integer'),
            ],
        ),
        'files' => new OA\Schema(
            type: 'object',
            properties: [
                'count' => new OA\Schema(type: 'integer'),
                'totalSize' => new OA\Schema(type: 'integer'),
            ],
        ),
        'coverImage' => new OA\Schema(
            type: 'object',
            nullable: true,
            properties: [
                'id' => new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
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
            description: 'The check of every song file, when deleteFiles is true',
            nullable: true,
            oneOf: [
                new OA\Schema(ref: FileDeletionPreviewResource::class),
            ],
        ),
    ],
    required: ['album', 'files', 'affected'],
    component: 'AlbumDeletePreviewResource',
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
