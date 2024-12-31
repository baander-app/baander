<?php

namespace App\Modules\FFmpeg\Exporters;

use App\Modules\FFmpeg\Drivers\PHPFFMpeg;

interface PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string;
}
