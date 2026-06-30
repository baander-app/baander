<?php

declare(strict_types=1);

namespace App\QoL\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class UtilizationSample implements JsonSerializable
{
    public function __construct(
        public readonly float             $cpuPercent,
        public readonly float             $gpuPercent,
        public readonly float             $encodeFps,
        public readonly int               $sourceHeight,
        public readonly string            $sourceCodec,
        public readonly bool              $hardwareAccelerated,
        public readonly int               $targetBitrate,
        public readonly string            $qualityTier,
        public readonly int               $activeStreams,
        public readonly DateTimeImmutable $measuredAt = new DateTimeImmutable(),
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            cpuPercent: (float)($data['cpu_percent'] ?? 0.0),
            gpuPercent: (float)($data['gpu_percent'] ?? 0.0),
            encodeFps: (float)($data['encode_fps'] ?? 0.0),
            sourceHeight: (int)($data['source_height'] ?? 0),
            sourceCodec: (string)($data['source_codec'] ?? ''),
            hardwareAccelerated: (bool)($data['hardware_accelerated'] ?? false),
            targetBitrate: (int)($data['target_bitrate'] ?? 0),
            qualityTier: (string)($data['quality_tier'] ?? ''),
            activeStreams: (int)($data['active_streams'] ?? 0),
            measuredAt: isset($data['measured_at'])
                ? new DateTimeImmutable($data['measured_at'])
                : new DateTimeImmutable(),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'cpu_percent' => $this->cpuPercent,
            'gpu_percent' => $this->gpuPercent,
            'encode_fps' => $this->encodeFps,
            'source_height' => $this->sourceHeight,
            'source_codec' => $this->sourceCodec,
            'hardware_accelerated' => $this->hardwareAccelerated,
            'target_bitrate' => $this->targetBitrate,
            'quality_tier' => $this->qualityTier,
            'active_streams' => $this->activeStreams,
            'measured_at' => $this->measuredAt->format(DateTimeInterface::ATOM),
        ];
    }
}
