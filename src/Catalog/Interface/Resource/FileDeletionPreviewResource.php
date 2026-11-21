<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\FileDeletionPreview;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** The `fileDeletion` of an album or song delete preview that asked for the file checks. */
#[OA\Schema(
    type: 'object',
    properties: [
        'allowed' => new OA\Schema(description: 'The delete with files would go ahead', type: 'boolean'),
        'libraryBusy' => new OA\Schema(
            description: 'A scan or another delete with files holds the library, which refuses the delete',
            type: 'boolean',
        ),
        'files' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'path' => new OA\Schema(type: 'string'),
                    'verdict' => new OA\Schema(
                        type: 'string',
                        enum: ['deletable', 'missing', 'outside_root', 'directory_not_writable'],
                    ),
                    'directory' => new OA\Schema(
                        description: 'The directory the server cannot write, for directory_not_writable',
                        type: 'string',
                        nullable: true,
                    ),
                ],
            ),
        ),
    ],
    required: ['allowed', 'libraryBusy', 'files'],
    component: 'FileDeletionPreviewResource',
)]
final class FileDeletionPreviewResource extends AbstractResource
{
    /**
     * @return array{allowed: bool, libraryBusy: bool, files: list<array{path: string, verdict: string, directory: string|null}>}
     */
    public static function from(mixed $source): array
    {
        assert($source instanceof FileDeletionPreview);

        return [
            'allowed' => $source->allowed,
            'libraryBusy' => $source->libraryBusy,
            'files' => $source->files,
        ];
    }
}
