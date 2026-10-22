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
        public int $index = 0,
        public bool $isForced = false,
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
            isDefault: (bool) ($stream['disposition']['default'] ?? false),
            index: (int) ($stream['index'] ?? 0),
            isForced: (bool) ($stream['disposition']['forced'] ?? false),
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
            title: $data['title'] ?? '',
            isDefault: (bool) ($data['isDefault'] ?? false),
            index: (int) ($data['index'] ?? 0),
            isForced: (bool) ($data['isForced'] ?? false),
        );
    }

    public function trackKey(): string
    {
        return 's' . $this->index;
    }

    public function isText(): bool
    {
        return in_array($this->codec, ['subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text', 'microdvd', 'mpl2', 'jacosub', 'sami', 'realtext', 'subviewer', 'subviewer1', 'vplayer', 'pjs'], true);
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
            && $this->isDefault === $other->isDefault
            && $this->index === $other->index
            && $this->isForced === $other->isForced;
    }

    /**
     * @return array{language: string, codec: string, title: string, isDefault: bool, index: int, isForced: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'language' => $this->language,
            'codec' => $this->codec,
            'title' => $this->title,
            'isDefault' => $this->isDefault,
            'index' => $this->index,
            'isForced' => $this->isForced,
        ];
    }
}
