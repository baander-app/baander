<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;

final class EncoderProfileFingerprintAdapter implements EncoderProfileFingerprintPortInterface
{
    public function __construct(private readonly HardwareCapabilitiesProber $prober)
    {
    }

    public function getName(): string
    {
        return $this->prober->getProfile()->getName();
    }
}
