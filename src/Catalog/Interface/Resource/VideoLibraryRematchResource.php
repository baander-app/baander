<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'libraryId' => new OA\Schema(type: 'string', description: 'Library public ID'),
        'totalTitles' => new OA\Schema(type: 'integer'),
        'handMatchedTitles' => new OA\Schema(type: 'integer'),
        'handAssignedFiles' => new OA\Schema(type: 'integer'),
        'confirmed' => new OA\Schema(type: 'boolean'),
        'force' => new OA\Schema(type: 'boolean'),
        'queued' => new OA\Schema(type: 'integer'),
        'skipped' => new OA\Schema(type: 'integer'),
    ],
    required: ['libraryId', 'totalTitles', 'handMatchedTitles', 'handAssignedFiles'],
    component: 'VideoLibraryRematchResource',
)]
final class VideoLibraryRematchResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip([
            'libraryId', 'totalTitles', 'handMatchedTitles', 'handAssignedFiles', 'confirmed', 'force', 'queued', 'skipped',
        ]));
    }
}
