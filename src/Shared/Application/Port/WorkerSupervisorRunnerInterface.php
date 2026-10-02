<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;

interface WorkerSupervisorRunnerInterface
{
    public function run(WorkerRuntimeConfiguration $configuration): int;
}
