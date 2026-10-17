<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\TranscodeExecutionClaim;

interface TranscodeLoopStarterInterface
{
    /** @param callable(): bool $stopRequested */
    public function run(TranscodeExecutionClaim $claim, callable $stopRequested): void;
}
