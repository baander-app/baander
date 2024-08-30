<?php

namespace App\Packages\FFmpeg\Support;

/**
 * @method static \App\Packages\FFmpeg\Http\DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static \App\Packages\FFmpeg\MediaOpener fromDisk($disk)
 * @method static \App\Packages\FFmpeg\MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static \App\Packages\FFmpeg\MediaOpener open($path)
 * @method static \App\Packages\FFmpeg\MediaOpener openUrl($path, array $headers = [])
 * @method static \App\Packages\FFmpeg\MediaOpener cleanupTemporaryFiles()
 *
 * @see \App\Packages\FFmpeg\MediaOpener
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
