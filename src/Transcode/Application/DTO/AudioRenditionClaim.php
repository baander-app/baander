<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioRenditionClaim
{
    public function __construct(
        public AudioRenditionRuntimeSnapshot $runtime,
        public MediaWorkerIdentity $worker,
        public string $token,
    ) {}
}
