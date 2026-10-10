<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/**
 * The `data` of an album, song, movie or artist delete response, and of the matching
 * `app:<noun>:delete --json`.
 */
#[OA\Schema(
    schema: 'CatalogDeletionResource',
    properties: [
        new OA\Property(
            property: 'deleted',
            description: 'Rows deleted by kind: albums, songs and coverImages for an album; songs for a song; movies and videos for a movie; artists and coverImages for an artist',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
        ),
        new OA\Property(property: 'files', properties: [
            new OA\Property(property: 'removed', description: 'Audio files unlinked', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'missing', description: 'Audio files that were already gone', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(
                property: 'left',
                description: 'Audio files still on disk; the next scan imports them again',
                type: 'array',
                items: new OA\Items(properties: [
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', enum: ['outside_root', 'unlink_failed', 'claim_lost']),
                    new OA\Property(property: 'detail', type: 'string'),
                ]),
            ),
        ], type: 'object'),
    ],
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
