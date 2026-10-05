<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

/** Identifies the encoder configuration used by persisted governor learning. */
interface EncoderProfileFingerprintPortInterface
{
    public function getName(): string;
}
