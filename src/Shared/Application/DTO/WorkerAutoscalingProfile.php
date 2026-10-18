<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable admission and timing inputs included in both unit recipes. */
#[Exclude]
final readonly class WorkerAutoscalingProfile
{
    public function __construct(
        public int $minimumConcurrency = 1,
        public int $maximumConcurrency = 1,
        public int $sampleIntervalMs = 1000,
        public int $maxSampleAgeMs = 3000,
        public int $targetBacklogAgeMs = 10000,
        public int $scaleUpSustainMs = 3000,
        public int $scaleDownSustainMs = 30000,
        public int $changeCooldownMs = 5000,
        public int $cpuCeilingPercent = 85,
        public int $memoryCeilingPercent = 85,
        public int $pidsCeilingPercent = 85,
        public int $memoryStopPercent = 95,
        public int $pidsStopPercent = 95,
        public int $coldDurationMs = 1000,
    ) {
        if ($minimumConcurrency < 1 || $maximumConcurrency > 4 || $minimumConcurrency > $maximumConcurrency) {
            throw new \InvalidArgumentException('Ordinary concurrency requires a minimum and maximum between one and four.');
        }
        foreach ([$sampleIntervalMs, $maxSampleAgeMs, $targetBacklogAgeMs, $scaleUpSustainMs,
            $scaleDownSustainMs, $changeCooldownMs, $coldDurationMs] as $milliseconds) {
            if ($milliseconds < 100 || $milliseconds > 3600000) {
                throw new \InvalidArgumentException('Autoscaling durations require 100 through 3600000 milliseconds.');
            }
        }
        foreach ([$cpuCeilingPercent, $memoryCeilingPercent, $pidsCeilingPercent, $memoryStopPercent, $pidsStopPercent] as $percent) {
            if ($percent < 1 || $percent > 100) {
                throw new \InvalidArgumentException('Autoscaling pressure thresholds require one through 100 percent.');
            }
        }
        if ($maxSampleAgeMs < 2 * $sampleIntervalMs || $scaleUpSustainMs < $sampleIntervalMs
            || $scaleDownSustainMs <= $scaleUpSustainMs || $changeCooldownMs < $sampleIntervalMs
            || $memoryStopPercent <= $memoryCeilingPercent || $pidsStopPercent <= $pidsCeilingPercent) {
            throw new \InvalidArgumentException('Autoscaling freshness, stabilization and pressure thresholds are inconsistent.');
        }
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 2048) {
            throw new \InvalidArgumentException('Autoscaling profile exceeds 2048 bytes.');
        }
        try {
            $value = json_decode($json, false, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Autoscaling profile requires a bounded JSON object.');
        }
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Autoscaling profile requires a JSON object.');
        }
        $fields = get_object_vars($value);
        $expected = array_keys(get_object_vars(new self()));
        if (array_diff(array_keys($fields), $expected) !== [] || array_diff($expected, array_keys($fields)) !== []) {
            throw new \InvalidArgumentException('Autoscaling profile has unknown or missing fields.');
        }
        foreach ($fields as $field) {
            if (!is_int($field)) {
                throw new \InvalidArgumentException('Autoscaling profile fields require JSON integers.');
            }
        }
        return new self(...$fields);
    }

    public function toJson(): string
    {
        return json_encode(get_object_vars($this), JSON_THROW_ON_ERROR);
    }

    public function minimumMemoryMiB(string $unit): int
    {
        return match ($unit) {
            'control' => 2944,
            'execution' => 4160 + 320 * ($this->maximumConcurrency - 1),
            default => throw new \InvalidArgumentException('Unknown worker unit.'),
        };
    }

    public function minimumPids(string $unit): int
    {
        return match ($unit) {
            'control' => 129,
            'execution' => 256 + $this->maximumConcurrency - 1,
            default => throw new \InvalidArgumentException('Unknown worker unit.'),
        };
    }

    /** @return list<string> */
    public function consumerIds(): array
    {
        return array_map(static fn (int $slot): string => 'consumer-' . $slot, range(0, $this->maximumConcurrency - 1));
    }
}
