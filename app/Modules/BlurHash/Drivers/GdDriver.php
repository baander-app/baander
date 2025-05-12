<?php

namespace App\Modules\BlurHash\Drivers;

use App\Modules\BlurHash\Exceptions\DriverNotFoundException;
use App\Modules\BlurHash\Exceptions\UnableToConvertColorException;
use App\Modules\BlurHash\Exceptions\UnableToCreateImageException;
use App\Modules\BlurHash\Exceptions\UnableToReadFileException;
use App\Modules\BlurHash\Exceptions\UnableToSetPixelException;
use App\Modules\BlurHash\Exceptions\UnsupportedFileException;
use GdImage;

class GdDriver extends Driver
{
    public GdImage $image;

    /**
     * Create a new GdDriver instance.
     *
     * @throws DriverNotFoundException
     */
    public function __construct(int $maxSize)
    {
        if (! extension_loaded('gd')) {
            throw new DriverNotFoundException(
                'GD extension is not loaded.',
            );
        }

        parent::__construct($maxSize);
    }

    /**
     * {@inheritdoc}
     *
     * @throws DriverNotFoundException
     * @throws UnableToReadFileException
     * @throws UnsupportedFileException
     */
    public function read(string $path): GdImage
    {
        $supported = [
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_BMP => 'imagecreatefrombmp',
            IMAGETYPE_WBMP => 'imagecreatefromwbmp',
            IMAGETYPE_XBM => 'imagecreatefromxbm',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        ];

        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            $supported[IMAGETYPE_AVIF] = 'imagecreatefromavif';
        }

        $type = exif_imagetype($path);

        if ($type === false || ! isset($supported[$type])) {
            throw new UnsupportedFileException(
                sprintf('The "%s" is not a supported image file.', $path),
            );
        }

        $callback = $supported[$type];

        if (! function_exists($callback)) {
            throw new DriverNotFoundException(
                sprintf('Function "%s" not found.', $callback),
            );
        }

        $image = call_user_func($callback, $path);

        if (! ($image instanceof GdImage)) {
            throw new UnableToReadFileException(
                sprintf('Unable to create an image resource from the "%s" file.', $path),
            );
        }

        return $image;
    }

    /**
     * {@inheritdoc}
     *
     * @param  GdImage  $origin
     *
     * @throws UnableToConvertColorException
     */
    public function resize(object $origin): GdImage
    {
        if (imagepalettetotruecolor($origin) === false) {
            throw new UnableToConvertColorException();
        }

        [$originWidth, $originHeight] = $this->size($origin);

        $scale = $this->scale($originWidth, $originHeight);

        if ($scale <= 1.0) {
            return $origin;
        }

        $width = (int) ceil($originWidth / $scale);

        $height = (int) ceil($originHeight / $scale);

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return $origin;
        }

        if (imagesavealpha($image, true) === false) {
            return $origin;
        }

        if (imagealphablending($image, false) === false) {
            return $origin;
        }

        $background = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 127);

        if ($background === false) {
            return $origin;
        }

        if (imagefill($image, 0, 0, $background) === false) {
            return $origin;
        }

        if (imagealphablending($image, true) === false) {
            return $origin;
        }

        $resized = imagecopyresampled(
            $image,
            $origin,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $originWidth,
            $originHeight,
        );

        if ($resized === false) {
            return $origin;
        }

        return $image;
    }

    /**
     * {@inheritdoc}
     *
     * @param  GdImage  $image
     */
    public function size(object $image): array
    {
        return [ // @phpstan-ignore-line
            imagesx($image),
            imagesy($image),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param  GdImage  $image
     */
    public function color(object $image, int $x, int $y): array|false
    {
        $rgb = imagecolorat($image, $x, $y);

        if ($rgb === false) {
            return false;
        }

        return [
            ($rgb >> 16) & 0xFF,
            ($rgb >> 8) & 0xFF,
            $rgb & 0xFF,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnableToCreateImageException
     */
    public function create(int $width, int $height): static
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new UnableToCreateImageException();
        }

        $this->image = $image;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnableToSetPixelException
     */
    public function pixel(int $x, int $y, array $color): bool
    {
        $draw = imagecolorallocate($this->image, ...$color);

        if ($draw === false) {
            throw new UnableToSetPixelException();
        }

        return imagesetpixel($this->image, $x, $y, $draw);
    }
}
