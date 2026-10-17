<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;

final class EncoderProfileFingerprintAdapter implements EncoderProfileFingerprintPortInterface
{
    public function __construct(private readonly WorkerEncoderCapabilitiesSnapshotStore $snapshots)
    {
    }

    public function getName(): string
    {
        $snapshot = $this->snapshots->read() ?? throw new \RuntimeException('Worker encoder capabilities are unavailable');

        return $snapshot->profile->getName();
    }
}
