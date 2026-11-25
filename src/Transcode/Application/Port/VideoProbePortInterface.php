<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\Exception\UnsupportedMediaContainerException;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

interface VideoProbePortInterface
{
    /** Refuses content that is not a video container the library supports with an UnsupportedMediaContainerException. */
    public function probeVideo(string $sourcePath): VideoProbeResult;
}
