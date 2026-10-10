<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\FileDeletionPreview;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/** The `fileDeletion` of an album or song delete preview that asked for the file checks. */
#[OA\Schema(
    schema: 'FileDeletionPreviewResource',
    properties: [
        new OA\Property(property: 'allowed', description: 'The delete with files would go ahead', type: 'boolean'),
        new OA\Property(property: 'scanInProgress', description: 'A scan or another delete with files holds the library, which refuses the delete', type: 'boolean'),
        new OA\Property(property: 'files', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'path', type: 'string'),
            new OA\Property(property: 'verdict', type: 'string', enum: ['deletable', 'missing', 'outside_root', 'directory_not_writable']),
            new OA\Property(property: 'directory', description: 'The directory the server cannot write, for directory_not_writable', type: 'string', nullable: true),
        ])),
    ],
)]
final class FileDeletionPreviewResource extends AbstractResource
{
    /**
     * @return array{allowed: bool, scanInProgress: bool, files: list<array{path: string, verdict: string, directory: string|null}>}
     */
    public static function from(mixed $source): array
    {
        assert($source instanceof FileDeletionPreview);

        return [
            'allowed' => $source->allowed,
            'scanInProgress' => $source->scanInProgress,
            'files' => $source->files,
        ];
    }
}
