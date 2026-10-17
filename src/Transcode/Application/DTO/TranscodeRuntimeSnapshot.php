<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\Uuid;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class TranscodeRuntimeSnapshot
{
    public function __construct(
        public Uuid $jobId,
        public Uuid $sessionId,
        public string $requestId,
        public DesiredTranscodeState $desired,
        public int $desiredRevision,
        public int $observedRevision,
        public int $generation,
        public string $outcome,
        public ?string $outputDirectory,
        public ?string $error,
        public bool $ownershipLive = false,
    ) {}
}
