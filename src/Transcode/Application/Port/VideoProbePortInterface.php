<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Domain\ValueObject\VideoProbeResult;

interface VideoProbePortInterface
{
    public function probeVideo(string $sourcePath): VideoProbeResult;
}
