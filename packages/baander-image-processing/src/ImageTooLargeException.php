<?php

declare(strict_types=1);

namespace Baander\ImageProcessing;

/**
 * An image whose header declares more pixels than GD may decode.
 *
 * GD allocates the whole bitmap, four bytes a pixel, from the size the header declares before it
 * reads any pixel data, so a header of a few dozen bytes can claim gigabytes. getimagesize()
 * reads only the header, so the bound is checked on its result before any decoder runs.
 */
final class ImageTooLargeException extends \RuntimeException
{
    public const int MAX_PIXELS = 50_000_000;

    /**
     * @param array{0: int, 1: int, ...} $imageInfo the getimagesize() result for $path
     *
     * @throws self when the declared size exceeds MAX_PIXELS
     */
    public static function assertDecodable(array $imageInfo, string $path): void
    {
        [$width, $height] = $imageInfo;
        if ($width * $height > self::MAX_PIXELS) {
            throw new self(sprintf(
                'Image "%s" declares %d by %d pixels; at most %d pixels are decoded.',
                $path,
                $width,
                $height,
                self::MAX_PIXELS,
            ));
        }
    }
}
