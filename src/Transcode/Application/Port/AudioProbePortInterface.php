<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\AudioProbeResult;

interface AudioProbePortInterface
{
    public function probeAudio(string $sourcePath): AudioProbeResult;
}
