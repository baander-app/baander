<?php

namespace Baander\FFMpeg\Exporters;

use Baander\FFMpeg\Drivers\PHPFFMpeg;

interface PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string;
}
