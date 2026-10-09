<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Command\Cover\CoverImageView;
use App\Shared\Interface\Resource\AbstractResource;

/**
 * The `data` of a cover upload response, and of `app:album:cover:set --json` and `app:artist:cover:set --json`.
 */
final class CoverImageResource extends AbstractResource
{
    /**
     * @return array{publicId: string, url: string, size: int, width: int, height: int}
     */
    public static function from(mixed $source): array
    {
        assert($source instanceof CoverImageView);

        return [
            'publicId' => $source->publicId,
            'url' => '/api/images/' . $source->publicId . '/file',
            'size' => $source->size,
            'width' => $source->width,
            'height' => $source->height,
        ];
    }
}
