<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use JsonSerializable;

/**
 * Represents a single audio stream discovered by FFprobe.
 *
 * Each stream carries a BCP-47 language tag (e.g. "en", "es", "fr")
 * extracted from the stream's `tags.language` field.
 */
final readonly class AudioStreamInfo implements JsonSerializable
{
    public function __construct(
        public string $language,
        public string $codec,
        public int $channels,
        public int $sampleRate,
        public int $bitrate,
        public string $title,
        public bool $isDefault,
        public string $channelLayout = '',
    ) {
    }

    /**
     * Construct from an FFprobe stream array.
     *
     * @param array<string, mixed> $stream Single stream from FFprobe JSON output
     */
    public static function fromProbeStream(array $stream): self
    {
        $tags = $stream['tags'] ?? [];
        $language = $tags['language'] ?? 'und';
        $title = $tags['title'] ?? $language;

        return new self(
            language: $language,
            codec: $stream['codec_name'] ?? 'unknown',
            channels: (int) ($stream['channels'] ?? 2),
            sampleRate: (int) ($stream['sample_rate'] ?? 48_000),
            bitrate: (int) ($stream['bit_rate'] ?? 0),
            title: $title,
            isDefault: (bool) ($stream['disposition']['default'] ?? false),
            channelLayout: (string) ($stream['channel_layout'] ?? ''),
        );
    }

    /**
     * Construct from a previously serialized array (JSONB deserialization).
     * @param array<string, mixed> $data
     */
    public static function fromSerialized(array $data): self
    {
        return new self(
            language: $data['language'] ?? 'und',
            codec: $data['codec'] ?? 'unknown',
            channels: (int) ($data['channels'] ?? 2),
            sampleRate: (int) ($data['sampleRate'] ?? 48_000),
            bitrate: (int) ($data['bitrate'] ?? 0),
            title: $data['title'] ?? '',
            isDefault: (bool) ($data['isDefault'] ?? false),
            channelLayout: (string) ($data['channelLayout'] ?? ''),
        );
    }

    public function getDisplayName(): string
    {
        return $this->title !== '' && $this->title !== $this->language
            ? $this->title
            : $this->language;
    }

    public function equals(self $other): bool
    {
        return $this->language === $other->language
            && $this->codec === $other->codec
            && $this->channels === $other->channels
            && $this->sampleRate === $other->sampleRate
            && $this->bitrate === $other->bitrate
            && $this->title === $other->title
            && $this->isDefault === $other->isDefault
            && $this->channelLayout === $other->channelLayout;
    }

    /**
     * @return array{language: string, codec: string, channels: int, sampleRate: int, bitrate: int, title: string, isDefault: bool, channelLayout: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'language' => $this->language,
            'codec' => $this->codec,
            'channels' => $this->channels,
            'sampleRate' => $this->sampleRate,
            'bitrate' => $this->bitrate,
            'title' => $this->title,
            'isDefault' => $this->isDefault,
            'channelLayout' => $this->channelLayout,
        ];
    }
}
