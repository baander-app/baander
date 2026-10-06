<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\HardwareTranscodeDiagnosticsReport;

interface HardwareTranscodeDiagnosticsInterface
{
    public function inspect(): HardwareTranscodeDiagnosticsReport;
}
