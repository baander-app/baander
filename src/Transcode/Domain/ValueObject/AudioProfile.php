<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

final readonly class AudioProfile implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $codec,
        public int $bitrate,
        public string $channelLayout,
        public int $channelCount,
        public int $sampleRate,
        public LoudnessStandard $loudnessStandard,
        public bool $downmixSurround,
        public bool $applyDrc,
        public float $drcRatio,
        public int $drcThreshold,
    ) {
    }

    public static function mobileMono(): self
    {
        return new self(
            name: 'mobile_mono',
            codec: 'aac',
            bitrate: 32_000,
            channelLayout: '1.0',
            channelCount: 1,
            sampleRate: 44_100,
            loudnessStandard: LoudnessStandard::Mobile,
            downmixSurround: false,
            applyDrc: true,
            drcRatio: 6.0,
            drcThreshold: -20,
        );
    }

    public static function mobileStereo(): self
    {
        return new self(
            name: 'mobile_stereo',
            codec: 'aac',
            bitrate: 64_000,
            channelLayout: '2.0',
            channelCount: 2,
            sampleRate: 44_100,
            loudnessStandard: LoudnessStandard::Mobile,
            downmixSurround: true,
            applyDrc: true,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function streamingStereo(): self
    {
        return new self(
            name: 'streaming_stereo',
            codec: 'aac',
            bitrate: 128_000,
            channelLayout: '2.0',
            channelCount: 2,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::Streaming,
            downmixSurround: true,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function streaming51(): self
    {
        return new self(
            name: 'streaming_5.1',
            codec: 'aac',
            bitrate: 256_000,
            channelLayout: '5.1',
            channelCount: 6,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::Streaming,
            downmixSurround: false,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function broadcastStereo(): self
    {
        return new self(
            name: 'broadcast_stereo',
            codec: 'aac',
            bitrate: 192_000,
            channelLayout: '2.0',
            channelCount: 2,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::EbuR128,
            downmixSurround: false,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function broadcast51(): self
    {
        return new self(
            name: 'broadcast_5.1',
            codec: 'aac',
            bitrate: 384_000,
            channelLayout: '5.1',
            channelCount: 6,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::EbuR128,
            downmixSurround: false,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function hifiStereo(): self
    {
        return new self(
            name: 'hifi_stereo',
            codec: 'aac',
            bitrate: 256_000,
            channelLayout: '2.0',
            channelCount: 2,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::Dialogue,
            downmixSurround: false,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function opusStereo(): self
    {
        return new self(
            name: 'opus_stereo',
            codec: 'opus',
            bitrate: 96_000,
            channelLayout: '2.0',
            channelCount: 2,
            sampleRate: 48_000,
            loudnessStandard: LoudnessStandard::Streaming,
            downmixSurround: true,
            applyDrc: false,
            drcRatio: 4.0,
            drcThreshold: -24,
        );
    }

    public static function fromString(string $name): self
    {
        return match ($name) {
            'mobile_mono' => self::mobileMono(),
            'mobile_stereo' => self::mobileStereo(),
            'streaming_stereo' => self::streamingStereo(),
            'streaming_5.1' => self::streaming51(),
            'broadcast_stereo' => self::broadcastStereo(),
            'broadcast_5.1' => self::broadcast51(),
            'hifi_stereo' => self::hifiStereo(),
            'opus_stereo' => self::opusStereo(),
            default => throw new InvalidArgumentException(sprintf('Unknown audio profile: "%s".', $name)),
        };
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'codec' => $this->codec,
            'bitrate' => $this->bitrate,
            'channelLayout' => $this->channelLayout,
            'channelCount' => $this->channelCount,
            'sampleRate' => $this->sampleRate,
            'loudnessStandard' => $this->loudnessStandard->value,
            'downmixSurround' => $this->downmixSurround,
            'applyDrc' => $this->applyDrc,
            'drcRatio' => $this->drcRatio,
            'drcThreshold' => $this->drcThreshold,
        ];
    }
}
