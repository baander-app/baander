<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

enum MediaTrack: string
{
    case Video = 'video';
    case Audio = 'audio';
}
