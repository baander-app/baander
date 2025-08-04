<?php

namespace App\Modules\FFmpeg\Support;

use App\Modules\FFmpeg\Http\DynamicHLSPlaylist;
use App\Modules\FFmpeg\MediaOpener;

/**
 * @method static DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static MediaOpener fromDisk($disk)
 * @method static MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static MediaOpener open($path)
 * @method static MediaOpener openUrl($path, array $headers = [])
 * @method static MediaOpener cleanupTemporaryFiles()
 *
 * @see \App\Modules\FFmpeg\MediaOpener
 */
class FFMpeg
{
    public static function instance(): MediaOpenerFactory
    {
        return Container::get('webman-ffmpeg');
    }

    public static function __callStatic($name, $arguments)
    {
        return static::instance()->{$name}(...$arguments);
    }
}
