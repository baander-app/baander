<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/**
 * The `data` of an album, song, movie or artist delete response, and of the matching
 * `app:<noun>:delete --json`.
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'deleted' => new OA\Schema(
            description: 'Rows deleted by kind: albums, songs and coverImages for an album; songs for a song; movies and videos for a movie; artists and coverImages for an artist',
            type: 'object',
            additionalProperties: new OA\Schema(type: 'integer'),
        ),
        'files' => new OA\Schema(
            type: 'object',
            properties: [
                'removed' => new OA\Schema(
                    description: 'Audio files unlinked',
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
                'missing' => new OA\Schema(
                    description: 'Audio files that were already gone',
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
                'left' => new OA\Schema(
                    description: 'Audio files still on disk; the next scan imports them again',
                    type: 'array',
                    items: new OA\Schema(
                        properties: [
                            'path' => new OA\Schema(type: 'string'),
                            'reason' => new OA\Schema(
                                type: 'string',
                                enum: ['outside_root', 'unlink_failed', 'claim_lost'],
                            ),
                            'detail' => new OA\Schema(type: 'string'),
                        ],
                    ),
                ),
            ],
        ),
    ],
    required: ['deleted', 'files'],
    component: 'CatalogDeletionResource',
)]
final class CatalogDeletionResource extends AbstractResource
{
    /**
     * @return array{deleted: array<string, int>, files: array{removed: list<string>, missing: list<string>, left: list<array{path: string, reason: string, detail: string}>}}
     */
    public static function from(mixed $source): array
    {
        assert($source instanceof CatalogDeletionResult);

        return [
            'deleted' => $source->deleted,
            'files' => [
                'removed' => $source->removed,
                'missing' => $source->missing,
                'left' => $source->left,
            ],
        ];
    }
}
