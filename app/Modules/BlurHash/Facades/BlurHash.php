<?php

namespace App\Modules\BlurHash\Facades;

use GdImage;
use Illuminate\Support\Facades\Facade;
use Imagick;

/**
 * @method static string encode(mixed $data)
 * @method static GdImage|Imagick|Image decode(string $blurhash, int $width, int $height)
 * @method static \App\Modules\BlurHash\BlurHash setComponentX(int $componentX)
 * @method static \App\Modules\BlurHash\BlurHash setComponentY(int $componentY)
 * @method static \App\Modules\BlurHash\BlurHash setMaxSize(int $maxSize)
 */
class BlurHash extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'blurhash';
    }
}
