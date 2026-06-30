<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use JsonSerializable;

/**
 * Represents a single subtitle stream discovered by FFprobe.
 */
final readonly class SubtitleTrackInfo implements JsonSerializable
{
    public function __construct(
        public string $language,
        public string $codec,
        public string $title,
        public bool $isDefault,
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
            title: $title,
            isDefault: ($tags['default'] ?? 'false') === 'true',
        );
    }

    /**
     * Construct from a previously serialized array (JSONB deserialization).
     */
    public static function fromSerialized(array $data): self
    {
        return new self(
            language: $data['language'] ?? 'und',
            codec: $data['codec'] ?? 'unknown',
            title: $data['title'] ?? '',
            isDefault: (bool) ($data['isDefault'] ?? false),
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
            && $this->title === $other->title
            && $this->isDefault === $other->isDefault;
    }

    public function jsonSerialize(): array
    {
        return [
            'language' => $this->language,
            'codec' => $this->codec,
            'title' => $this->title,
            'isDefault' => $this->isDefault,
        ];
    }
}
