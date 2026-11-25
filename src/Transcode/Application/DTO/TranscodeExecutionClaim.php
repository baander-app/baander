<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class TranscodeExecutionClaim
{
    public function __construct(
        public TranscodeRuntimeSnapshot $runtime,
        public MediaWorkerIdentity $worker,
        public string $token,
    ) {}
}
