<?php

declare(strict_types=1);

namespace App\QoL\Domain\Service;

use App\QoL\Domain\ValueObject\UtilizationSample;

/**
 * Linear regression model predicting per-stream CPU cost from transcode parameters.
 *
 * Features: [intercept, normalized_height, normalized_bitrate, hwaccel]
 * Target: cpu_percent (0-100)
 *
 * Trains via ordinary least squares (normal equation) once MIN_SAMPLES reached.
 * Falls back to per-tier averaging when model is not yet trained.
 */
final class LearningModel
{
    public const int MIN_SAMPLES = 50;

    /** @var list<UtilizationSample> */
    private array $samples = [];

    /**
     * Regression coefficients: [beta0, beta1, beta2, beta3]
     * beta0 = intercept, beta1 = height, beta2 = bitrate, beta3 = hwaccel
     *
     * @var list<float>|null
     */
    private ?array $coefficients = null;

    public function addSample(UtilizationSample $sample): void
    {
        $this->samples[] = $sample;

        if (count($this->samples) >= self::MIN_SAMPLES && $this->coefficients === null) {
            $this->train();
        }
    }

    /**
     * Train via normal equation: β = (X'X)⁻¹X'y
     *
     * 4-feature OLS with Gaussian elimination for the 4×4 system.
     */
    private function train(): void
    {
        $n = count($this->samples);
        if ($n < self::MIN_SAMPLES) {
            return;
        }

        // Build X'X (4×4) and X'y (4×1)
        $xtx = array_fill(0, 4, array_fill(0, 4, 0.0));
        $xty = array_fill(0, 4, 0.0);

        foreach ($this->samples as $sample) {
            $row = [
                1.0,
                $sample->sourceHeight / 2160.0,
                $sample->targetBitrate / 20_000_000.0,
                $sample->hardwareAccelerated ? 1.0 : 0.0,
            ];

            for ($i = 0; $i < 4; $i++) {
                for ($j = 0; $j < 4; $j++) {
                    $xtx[$i][$j] += $row[$i] * $row[$j];
                }
                $xty[$i] += $row[$i] * $sample->cpuPercent;
            }
        }

        $beta = $this->solve4x4($xtx, $xty);

        if ($beta !== null) {
            $this->coefficients = $beta;
        }
    }

    /**
     * Solve 4×4 linear system Ax=b using Gaussian elimination with partial pivoting.
     *
     * @param list<list<float>> $a 4×4 matrix
     * @param list<float> $b 4-element vector
     *
     * @return list<float>|null Solution vector, or null if singular
     */
    private function solve4x4(array $a, array $b): ?array
    {
        $n = 4;

        // Augmented matrix
        for ($i = 0; $i < $n; $i++) {
            $a[$i][$n] = $b[$i];
        }

        // Forward elimination with partial pivoting
        for ($col = 0; $col < $n; $col++) {
            // Find pivot
            $maxRow = $col;
            $maxVal = abs($a[$col][$col]);
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($a[$row][$col]) > $maxVal) {
                    $maxVal = abs($a[$row][$col]);
                    $maxRow = $row;
                }
            }

            if ($maxVal < 1e-10) {
                return null; // Singular matrix
            }

            // Swap rows
            if ($maxRow !== $col) {
                [$a[$col], $a[$maxRow]] = [$a[$maxRow], $a[$col]];
            }

            // Eliminate below
            for ($row = $col + 1; $row < $n; $row++) {
                $factor = $a[$row][$col] / $a[$col][$col];
                for ($j = $col; $j <= $n; $j++) {
                    $a[$row][$j] -= $factor * $a[$col][$j];
                }
            }
        }

        // Back substitution
        $x = array_fill(0, $n, 0.0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $sum = 0.0;
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += $a[$i][$j] * $x[$j];
            }
            $x[$i] = ($a[$i][$n] - $sum) / $a[$i][$i];
        }

        return $x;
    }

    /**
     * Predict CPU% cost for given stream parameters.
     *
     * Returns null if model has not been trained yet.
     * Returns predicted CPU% (0-100 range, may slightly exceed due to linear extrapolation).
     */
    public function predict(int $sourceHeight, int $targetBitrate, bool $hardwareAccelerated): ?float
    {
        if ($this->coefficients === null) {
            return null;
        }

        [$b0, $b1, $b2, $b3] = $this->coefficients;

        return $b0
            + $b1 * ($sourceHeight / 2160.0)
            + $b2 * ($targetBitrate / 20_000_000.0)
            + $b3 * ($hardwareAccelerated ? 1.0 : 0.0);
    }

    /**
     * Per-tier fallback: average CPU% for samples matching the given tier.
     * Used when the regression model hasn't been trained yet.
     */
    public function averageCostForTier(string $qualityTier): ?float
    {
        $matching = array_filter(
            $this->samples,
            static fn(UtilizationSample $s): bool => $s->qualityTier === $qualityTier,
        );

        if ($matching === []) {
            return null;
        }

        return array_sum(array_map(
                static fn(UtilizationSample $s): float => $s->cpuPercent,
                $matching,
            )) / count($matching);
    }

    public function isReady(): bool
    {
        return count($this->samples) >= self::MIN_SAMPLES;
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    public function reset(): void
    {
        $this->samples = [];
        $this->coefficients = null;
    }

    /**
     * Serialize model state for persistence.
     */
    public function getState(): array
    {
        return [
            'samples' => array_map(static fn(UtilizationSample $s): array => $s->jsonSerialize(), $this->samples),
            'coefficients' => $this->coefficients,
        ];
    }

    /**
     * Restore model state from persistence.
     */
    public function restoreState(array $state): void
    {
        $this->samples = array_map(
            static fn(array $data): UtilizationSample => UtilizationSample::fromArray($data),
            $state['samples'] ?? [],
        );
        $this->coefficients = $state['coefficients'] ?? null;
    }
}
