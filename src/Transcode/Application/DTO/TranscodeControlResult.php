<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class TranscodeControlResult
{
    public function __construct(
        public TranscodeRuntimeSnapshot $runtime,
        public bool $accepted,
    ) {}
}
