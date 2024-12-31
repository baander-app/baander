<?php

namespace App\Modules\FFmpeg\Support;

/**
 * @method static \App\Modules\FFmpeg\Http\DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static \App\Modules\FFmpeg\MediaOpener fromDisk($disk)
 * @method static \App\Modules\FFmpeg\MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static \App\Modules\FFmpeg\MediaOpener open($path)
 * @method static \App\Modules\FFmpeg\MediaOpener openUrl($path, array $headers = [])
 * @method static \App\Modules\FFmpeg\MediaOpener cleanupTemporaryFiles()
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
