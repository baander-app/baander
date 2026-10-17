<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Capabilities observed by a worker; this is evidence, not a live hardware measurement. */
#[Exclude]
final readonly class WorkerEncoderCapabilitiesSnapshot
{
    public function __construct(
        public EncoderProfile $profile,
        public float $bitrateMultiplier,
        public string $workerIdentity,
        public int $observedAt,
    ) {
    }
}
