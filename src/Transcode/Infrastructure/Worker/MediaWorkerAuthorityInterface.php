<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

/** Private process admission, separate from per-job ownership. */
interface MediaWorkerAuthorityInterface
{
    public function assertActive(): void;
}
