<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\MediaOperation;

interface MediaExecutionPolicyInterface
{
    public function permits(MediaOperation $operation): bool;
    public function assertAllowed(MediaOperation $operation): void;
}
