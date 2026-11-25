<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/** Whether a library file is imported as a song or as a video. */
enum MediaKind
{
    case Audio;
    case Video;
}
