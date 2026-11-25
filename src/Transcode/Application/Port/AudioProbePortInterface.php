<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Exception\UnsupportedMediaContainerException;

interface AudioProbePortInterface
{
    /** Refuses content that is not an audio container the library supports with an UnsupportedMediaContainerException. */
    public function probeAudio(string $sourcePath): AudioProbeResult;
}
