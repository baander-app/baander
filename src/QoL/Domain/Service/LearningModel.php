<?php

declare(strict_types=1);

namespace App\QoL\Domain\Service;

use App\QoL\Domain\ValueObject\UtilizationSample;

/**
 * Linear regression model predicting a stream's encoder cost from its transcode parameters.
 *
 * Cost is the stream's own encoder CPU seconds per output second as a percentage of the
 * host's CPUs. Software and hardware-accelerated encodes are learned apart, each with its
 * own regression and per-tier averages, so one kind's samples never move the other's
 * predictions.
 *
 * Features: [intercept, normalized_height, normalized_bitrate]
 * Target: cpu_percent
 *
 * Each kind trains via ordinary least squares (normal equation) once it has MIN_SAMPLES
 * samples. Until then, or when its samples cannot separate height from bitrate, callers
 * fall back to per-tier averaging.
 */
final class LearningModel
{
    public const int MIN_SAMPLES = 50;

    private const int FEATURES = 3;

    /** @var list<UtilizationSample> */
    private array $samples = [];

    /**
     * Regression coefficients per encode kind: [beta0, beta1, beta2]
     * beta0 = intercept, beta1 = height, beta2 = bitrate
     *
     * @var array{software: list<float>|null, hardware: list<float>|null}
     */
    private array $coefficients = ['software' => null, 'hardware' => null];

    public function addSample(UtilizationSample $sample): void
    {
        $this->samples[] = $sample;

        $kind = self::kind($sample->hardwareAccelerated);
        if ($this->coefficients[$kind] === null) {
            $this->train($sample->hardwareAccelerated);
        }
    }

    /**
     * Train one encode kind via normal equation: β = (X'X)⁻¹X'y
     */
    private function train(bool $hardwareAccelerated): void
    {
        $samples = $this->samplesOf($hardwareAccelerated);
        if (count($samples) < self::MIN_SAMPLES) {
            return;
        }

        $xtx = array_fill(0, self::FEATURES, array_fill(0, self::FEATURES, 0.0));
        $xty = array_fill(0, self::FEATURES, 0.0);

        foreach ($samples as $sample) {
            $row = self::features($sample->sourceHeight, $sample->targetBitrate);

            for ($i = 0; $i < self::FEATURES; $i++) {
                for ($j = 0; $j < self::FEATURES; $j++) {
                    $xtx[$i][$j] += $row[$i] * $row[$j];
                }
                $xty[$i] += $row[$i] * $sample->cpuPercent;
            }
        }

        $this->coefficients[self::kind($hardwareAccelerated)] = $this->solve($xtx, $xty);
    }

    /**
     * Solve the linear system Ax=b using Gaussian elimination with partial pivoting.
     *
     * @param list<list<float>> $a square matrix
     * @param list<float> $b vector
     *
     * @return list<float>|null Solution vector, or null if singular
     */
    private function solve(array $a, array $b): ?array
    {
        $n = count($b);

        // Augmented matrix
        for ($i = 0; $i < $n; $i++) {
            $a[$i][$n] = $b[$i];
        }

        // Forward elimination with partial pivoting
        for ($col = 0; $col < $n; $col++) {
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

            if ($maxRow !== $col) {
                [$a[$col], $a[$maxRow]] = [$a[$maxRow], $a[$col]];
            }

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
     * Predict the cost of a stream with the given parameters from its own kind's regression.
     *
     * Returns null if that kind has not been trained yet. The value may slightly exceed the
     * observed range due to linear extrapolation.
     */
    public function predict(int $sourceHeight, int $targetBitrate, bool $hardwareAccelerated): ?float
    {
        $coefficients = $this->coefficients[self::kind($hardwareAccelerated)];
        if ($coefficients === null) {
            return null;
        }

        $row = self::features($sourceHeight, $targetBitrate);
        $cost = 0.0;
        for ($i = 0; $i < self::FEATURES; $i++) {
            $cost += $coefficients[$i] * $row[$i];
        }

        return $cost;
    }

    /**
     * Per-tier fallback: average cost of samples at the given tier. Given an encode kind,
     * only that kind's samples count; without one, the average is source-agnostic.
     */
    public function averageCostForTier(string $qualityTier, ?bool $hardwareAccelerated = null): ?float
    {
        $matching = array_filter(
            $hardwareAccelerated === null ? $this->samples : $this->samplesOf($hardwareAccelerated),
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
        $this->coefficients = ['software' => null, 'hardware' => null];
    }

    /**
     * Serialize model state for persistence.
     * @return array{samples: array<array-key, array<string, int|float|string|bool>>, coefficients: array{software: list<float>|null, hardware: list<float>|null}}
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
     * @param array<string, mixed> $state
     */
    public function restoreState(array $state): void
    {
        $this->samples = array_map(
            static fn(array $data): UtilizationSample => UtilizationSample::fromArray($data),
            $state['samples'] ?? [],
        );
        $coefficients = $state['coefficients'] ?? [];
        $this->coefficients = [
            'software' => self::coefficientsFrom($coefficients['software'] ?? null),
            'hardware' => self::coefficientsFrom($coefficients['hardware'] ?? null),
        ];
    }

    /** @return list<float>|null */
    private static function coefficientsFrom(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== self::FEATURES) {
            return null;
        }

        return array_map(static fn(mixed $beta): float => (float) $beta, $value);
    }

    /** @return list<UtilizationSample> */
    private function samplesOf(bool $hardwareAccelerated): array
    {
        return array_values(array_filter(
            $this->samples,
            static fn(UtilizationSample $s): bool => $s->hardwareAccelerated === $hardwareAccelerated,
        ));
    }

    /** @return list<float> */
    private static function features(int $sourceHeight, int $targetBitrate): array
    {
        return [1.0, $sourceHeight / 2160.0, $targetBitrate / 20_000_000.0];
    }

    /** @return 'software'|'hardware' */
    private static function kind(bool $hardwareAccelerated): string
    {
        return $hardwareAccelerated ? 'hardware' : 'software';
    }
}
