<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use JsonSerializable;

final readonly class VideoProbeResult implements JsonSerializable
{
    /**
     * @param array<AudioStreamInfo> $audioStreams All audio streams found by FFprobe
     * @param array<SubtitleTrackInfo> $subtitleStreams All subtitle streams found by FFprobe
     */
    public function __construct(
        public float $duration,
        public int $width,
        public int $height,
        public float $framerate,
        public ?ColorSpace $colorSpace,
        public ?string $colorRange,
        public ?string $pixFmt,
        public int $videoBitrate,
        public string $videoCodec,
        public int $audioChannels,
        public string $audioCodec,
        public int $audioSampleRate,
        public bool $isInterlaced,
        public array $audioStreams = [],
        public array $subtitleStreams = [],
    ) {
    }

    public static function fromProbeOutput(array $raw): self
    {
        $streams = $raw['streams'] ?? [];
        $format = $raw['format'] ?? [];

        $videoStream = [];
        $audioStream = [];
        $audioStreams = [];
        $subtitleStreams = [];

        foreach ($streams as $stream) {
            $codecType = $stream['codec_type'] ?? '';

            if ($codecType === 'video' && empty($videoStream)) {
                $videoStream = $stream;
            }

            if ($codecType === 'audio') {
                $audioInfo = AudioStreamInfo::fromProbeStream($stream);
                $audioStreams[] = $audioInfo;
                if (empty($audioStream)) {
                    $audioStream = $stream;
                }
            }

            if ($codecType === 'subtitle') {
                $subtitleStreams[] = SubtitleTrackInfo::fromProbeStream($stream);
            }
        }

        // Fallback: if no codec_type, use index-based heuristic
        if (empty($videoStream) && isset($streams[0])) {
            $videoStream = $streams[0];
        }
        if (empty($audioStream) && isset($streams[1])) {
            $audioStream = $streams[1];
        }

        // Resolve ColorSpace
        $colorSpace = null;
        if (isset($raw['colorSpace']) && is_array($raw['colorSpace'])) {
            $colorSpace = ColorSpace::fromArray($raw['colorSpace']);
        } else {
            $colorSpace = ColorSpace::fromProbeValues(
                $videoStream['color_primaries'] ?? null,
                $videoStream['color_transfer'] ?? null,
                $videoStream['color_space'] ?? null,
            );
        }

        $pixFmt = $videoStream['pix_fmt'] ?? null;

        $fieldOrder = $videoStream['field_order'] ?? '';
        $isInterlaced = in_array($fieldOrder, ['tt', 'bb', 'tb', 'bt', 'progressive'], true) === false
            && str_contains(strtolower($fieldOrder), 'interlaced');

        return new self(
            duration: (float) ($format['duration'] ?? 0.0),
            width: (int) ($videoStream['width'] ?? 0),
            height: (int) ($videoStream['height'] ?? 0),
            framerate: self::parseFramerate($videoStream['r_frame_rate'] ?? null),
            colorSpace: $colorSpace,
            colorRange: $videoStream['color_range'] ?? null,
            pixFmt: $pixFmt,
            videoBitrate: (int) ($videoStream['bit_rate'] ?? 0),
            videoCodec: $videoStream['codec_name'] ?? 'unknown',
            audioChannels: (int) ($audioStream['channels'] ?? 2),
            audioCodec: $audioStream['codec_name'] ?? 'unknown',
            audioSampleRate: (int) ($audioStream['sample_rate'] ?? 48_000),
            isInterlaced: $isInterlaced,
            audioStreams: $audioStreams,
            subtitleStreams: $subtitleStreams,
        );
    }

    /**
     * Construct from a previously serialized array (JSONB deserialization).
     *
     * Handles both legacy format (flat colorPrimaries/colorTransfer/colorMatrix
     * keys) and new format (nested colorSpace object).
     */
    public static function fromSerialized(array $data): self
    {
        $colorSpace = null;
        if (isset($data['colorSpace']) && is_array($data['colorSpace'])) {
            $colorSpace = ColorSpace::fromArray($data['colorSpace']);
        } else {
            $colorSpace = ColorSpace::fromProbeValues(
                $data['colorPrimaries'] ?? null,
                $data['colorTransfer'] ?? null,
                $data['colorMatrix'] ?? null,
            );
        }

        $audioStreams = [];
        foreach ($data['audioStreams'] ?? [] as $streamData) {
            $audioStreams[] = AudioStreamInfo::fromSerialized($streamData);
        }

        $subtitleStreams = [];
        foreach ($data['subtitleStreams'] ?? [] as $trackData) {
            $subtitleStreams[] = SubtitleTrackInfo::fromSerialized($trackData);
        }

        return new self(
            duration: (float) ($data['duration'] ?? 0.0),
            width: (int) ($data['width'] ?? 0),
            height: (int) ($data['height'] ?? 0),
            framerate: (float) ($data['framerate'] ?? 0.0),
            colorSpace: $colorSpace,
            colorRange: $data['colorRange'] ?? null,
            pixFmt: $data['pixFmt'] ?? null,
            videoBitrate: (int) ($data['videoBitrate'] ?? 0),
            videoCodec: $data['videoCodec'] ?? 'unknown',
            audioChannels: (int) ($data['audioChannels'] ?? 2),
            audioCodec: $data['audioCodec'] ?? 'unknown',
            audioSampleRate: (int) ($data['audioSampleRate'] ?? 48_000),
            isInterlaced: (bool) ($data['isInterlaced'] ?? false),
            audioStreams: $audioStreams,
            subtitleStreams: $subtitleStreams,
        );
    }

    private static function parseFramerate(?string $rate): float
    {
        if ($rate === null || $rate === '') {
            return 0.0;
        }

        if (str_contains($rate, '/')) {
            $parts = explode('/', $rate);
            $numerator = (float) ($parts[0] ?? 0);
            $denominator = (float) ($parts[1] ?? 1);

            return $denominator > 0 ? $numerator / $denominator : 0.0;
        }

        return (float) $rate;
    }

    public function isHdr(): bool
    {
        return $this->colorSpace !== null && $this->colorSpace->isHdr();
    }

    public function isSurround(): bool
    {
        return $this->audioChannels > 2;
    }

    public function is51(): bool
    {
        return $this->audioChannels === 6;
    }

    public function is71(): bool
    {
        return $this->audioChannels === 8;
    }

    /**
     * @return string[] BCP-47 language tags for all discovered audio streams
     */
    public function getAvailableAudioLanguages(): array
    {
        return array_map(static fn (AudioStreamInfo $s) => $s->language, $this->audioStreams);
    }

    /**
     * @return string[] BCP-47 language tags for all discovered subtitle streams
     */
    public function getAvailableSubtitleLanguages(): array
    {
        return array_map(static fn (SubtitleTrackInfo $s) => $s->language, $this->subtitleStreams);
    }

    public function hasAudioLanguage(string $language): bool
    {
        return in_array($language, $this->getAvailableAudioLanguages(), true);
    }

    public function hasSubtitleLanguage(string $language): bool
    {
        return in_array($language, $this->getAvailableSubtitleLanguages(), true);
    }

    public function jsonSerialize(): array
    {
        return [
            'duration' => $this->duration,
            'width' => $this->width,
            'height' => $this->height,
            'framerate' => $this->framerate,
            'colorSpace' => $this->colorSpace?->jsonSerialize(),
            // Legacy keys for backward compatibility with existing JSONB rows
            'colorPrimaries' => $this->colorSpace?->primaries,
            'colorTransfer' => $this->colorSpace?->transfer,
            'colorMatrix' => $this->colorSpace?->matrix,
            'colorRange' => $this->colorRange,
            'pixFmt' => $this->pixFmt,
            'videoBitrate' => $this->videoBitrate,
            'videoCodec' => $this->videoCodec,
            'audioChannels' => $this->audioChannels,
            'audioCodec' => $this->audioCodec,
            'audioSampleRate' => $this->audioSampleRate,
            'isHdr' => $this->isHdr(),
            'isInterlaced' => $this->isInterlaced,
            'audioStreams' => array_map(static fn (AudioStreamInfo $s) => $s->jsonSerialize(), $this->audioStreams),
            'subtitleStreams' => array_map(static fn (SubtitleTrackInfo $s) => $s->jsonSerialize(), $this->subtitleStreams),
        ];
    }
}
