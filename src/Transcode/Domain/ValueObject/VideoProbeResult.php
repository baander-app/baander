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
        public string $audioChannelLayout = '',
        public ?float $sourceTimestampOrigin = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
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

            if ($codecType === 'video' && empty($videoStream) && empty($stream['disposition']['attached_pic'])) {
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
        if (empty($videoStream) && isset($streams[0]) && !isset($streams[0]['codec_type'])
            && empty($streams[0]['disposition']['attached_pic'])) {
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
        $isInterlaced = in_array($fieldOrder, ['tt', 'bb', 'tb', 'bt'], true);

        $sourceTimestampOrigin = self::verifiedSourceTimestampOrigin($format, $videoStream, $raw['packets'] ?? []);

        return new self(
            duration: (float) ($format['duration'] ?? 0.0) - ($sourceTimestampOrigin ?? 0.0),
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
            audioChannelLayout: (string) ($audioStream['channel_layout'] ?? ''),
            sourceTimestampOrigin: $sourceTimestampOrigin,
        );
    }

    /**
     * Construct from a previously serialized array (JSONB deserialization).
     *
     * Handles both legacy format (flat colorPrimaries/colorTransfer/colorMatrix
     * keys) and new format (nested colorSpace object).
     * @param array<string, mixed> $data
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
            audioChannelLayout: (string) ($data['audioChannelLayout'] ?? ''),
            sourceTimestampOrigin: self::nonnegativeTimestamp($data['sourceTimestampOrigin'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $format
     * @param array<string, mixed> $videoStream
     */
    private static function verifiedSourceTimestampOrigin(array $format, array $videoStream, mixed $packets): ?float
    {
        $formatName = $format['format_name'] ?? null;
        $rate = self::parseFramerate($videoStream['r_frame_rate'] ?? null);
        $start = self::nonnegativeTimestamp($format['start_time'] ?? null);
        $end = self::nonnegativeTimestamp($format['duration'] ?? null);
        if (!is_string($formatName) || !in_array('matroska', explode(',', $formatName), true)
            || ($videoStream['codec_type'] ?? null) !== 'video'
            || ($videoStream['codec_name'] ?? null) !== 'h264' || ($videoStream['has_b_frames'] ?? null) !== 0
            || !is_finite($rate) || $rate <= 0.0 || $rate >= 1.0
            || !is_int($videoStream['index'] ?? null) || $videoStream['index'] < 0 || $start === null || $end === null
            || !is_array($packets) || !array_is_list($packets) || count($packets) > 128) {
            return null;
        }
        foreach ($packets as $packet) {
            if (!is_array($packet) || ($packet['stream_index'] ?? null) !== $videoStream['index']) {
                continue;
            }
            $origin = self::nonnegativeTimestamp($packet['pts_time'] ?? null);
            $flags = $packet['flags'] ?? null;
            if ($origin === null || !is_string($flags) || !str_contains($flags, 'K') || str_contains($flags, 'D')
                || $end <= $origin || $start < $origin || ($start === $origin && $origin === 0.0)) {
                return null;
            }
            return $origin;
        }
        return null;
    }

    private static function nonnegativeTimestamp(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $timestamp = (float) $value;
        return is_finite($timestamp) && $timestamp >= 0.0 ? $timestamp : null;
    }

    private static function parseFramerate(mixed $rate): float
    {
        if (!is_string($rate) || $rate === '') {
            return 0.0;
        }

        if (str_contains($rate, '/')) {
            $parts = explode('/', $rate);
            if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                return 0.0;
            }
            $numerator = (float) $parts[0];
            $denominator = (float) $parts[1];

            return $denominator > 0 ? $numerator / $denominator : 0.0;
        }

        return is_numeric($rate) ? (float) $rate : 0.0;
    }

    public function isHdr(): bool
    {
        return $this->colorSpace !== null && $this->colorSpace->isHdr();
    }

    public function hasAudio(): bool
    {
        return $this->audioStreams !== [];
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'duration' => $this->duration,
            'sourceTimestampOrigin' => $this->sourceTimestampOrigin,
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
            'audioChannelLayout' => $this->audioChannelLayout,
            'audioCodec' => $this->audioCodec,
            'audioSampleRate' => $this->audioSampleRate,
            'isHdr' => $this->isHdr(),
            'isInterlaced' => $this->isInterlaced,
            'audioStreams' => array_map(static fn (AudioStreamInfo $s) => $s->jsonSerialize(), $this->audioStreams),
            'subtitleStreams' => array_map(static fn (SubtitleTrackInfo $s) => $s->jsonSerialize(), $this->subtitleStreams),
        ];
    }
}
