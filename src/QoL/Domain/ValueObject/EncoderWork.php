<?php

declare(strict_types=1);

namespace App\QoL\Domain\ValueObject;

/**
 * One stream's encoder work: the CPU seconds its encoder consumed and the seconds of
 * output it produced.
 *
 * The stream's cost is CPU seconds per output second as a percentage of the host's
 * CPUs, the share of the host the stream needs to keep up with playback. Contention
 * from other encodes slows the encoder but does not change the CPU it spends per
 * output second, so the cost describes this stream alone.
 */
final readonly class EncoderWork
{
    /** Below one output segment, start-up work dominates and the ratio says little. */
    public const float MIN_OUTPUT_SECONDS = 6.0;

    public function __construct(
        public float $cpuSeconds,
        public float $outputSeconds,
    ) {
        if (!is_finite($cpuSeconds) || $cpuSeconds < 0.0 || !is_finite($outputSeconds) || $outputSeconds < 0.0) {
            throw new \InvalidArgumentException('Encoder work requires finite, non-negative CPU and output seconds.');
        }
    }

    /** The stream's cost as a percentage of the host's CPUs, or null while too little output exists. */
    public function costPercent(int $hostCpus): ?float
    {
        if ($hostCpus < 1) {
            throw new \InvalidArgumentException('Encoder cost requires at least one host CPU.');
        }
        if ($this->outputSeconds < self::MIN_OUTPUT_SECONDS) {
            return null;
        }

        return $this->cpuSeconds / $this->outputSeconds / $hostCpus * 100.0;
    }
}
