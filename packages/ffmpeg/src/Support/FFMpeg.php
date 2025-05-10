<?php

namespace Baander\FFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Baander\FFMpeg\Http\DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static \Baander\FFMpeg\MediaOpener fromDisk($disk)
 * @method static \Baander\FFMpeg\MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static \Baander\FFMpeg\MediaOpener open($path)
 * @method static \Baander\FFMpeg\MediaOpener openUrl($path, array $headers = [])
 * @method static \Baander\FFMpeg\MediaOpener cleanupTemporaryFiles()
 *
 * @see \Baander\FFMpeg\MediaOpener
 */
class FFMpeg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}
