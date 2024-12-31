<?php

namespace App\Packages\FFmpeg\Exporters;

use App\Packages\FFmpeg\Drivers\PHPFFMpeg;

interface PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string;
}
