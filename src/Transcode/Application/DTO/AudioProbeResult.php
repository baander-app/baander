<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioProbeResult
{
    /**
     * @param ?float $duration the selected stream's own duration, never one taken from the container
     * @param ?float $containerDuration the container's duration as ffprobe reports it; for Matroska this is the end timestamp
     * @param ?float $containerStartTime the container's first timestamp
     */
    public function __construct(
        public string $containerFormat,
        public int $streamIndex,
        public string $codec,
        public ?int $channels = null,
        public ?int $sampleRate = null,
        public ?int $bitrate = null,
        public ?float $duration = null,
        public ?float $containerDuration = null,
        public ?float $containerStartTime = null,
    ) {
        if ($containerFormat === '' || $streamIndex < 0 || $codec === '' || $codec === 'unknown') {
            throw new \UnexpectedValueException('Audio probe requires an identified container, codec and absolute stream index.');
        }
    }

    /**
     * The playing length of the audio in seconds: the selected stream's duration, else the container's
     * duration minus its start time, or null when neither is positive.
     */
    public function length(): ?float
    {
        if ($this->duration !== null && $this->duration > 0) {
            return $this->duration;
        }
        if ($this->containerDuration === null) {
            return null;
        }
        $span = $this->containerDuration - ($this->containerStartTime ?? 0.0);

        return $span > 0 ? $span : null;
    }

    /** @param array<string, mixed> $raw */
    public static function fromProbeOutput(array $raw): self
    {
        $selected = null;
        foreach ($raw['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) !== 'audio') {
                continue;
            }
            if ($selected === null || (!empty($stream['disposition']['default']) && empty($selected['disposition']['default']))) {
                $selected = $stream;
            }
        }
        $index = $selected['index'] ?? null;
        if (!is_int($index)) {
            throw new \UnexpectedValueException('Audio probe did not identify an absolute audio stream index.');
        }
        $positive = static fn (mixed $value): ?int => is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        $format = $raw['format'] ?? [];

        return new self(
            (string) ($format['format_name'] ?? ''),
            $index,
            (string) ($selected['codec_name'] ?? ''),
            $positive($selected['channels'] ?? null),
            $positive($selected['sample_rate'] ?? null),
            $positive($selected['bit_rate'] ?? null),
            self::positiveSeconds($selected['duration'] ?? null),
            self::positiveSeconds($format['duration'] ?? null),
            is_numeric($format['start_time'] ?? null) ? (float) $format['start_time'] : null,
        );
    }

    private static function positiveSeconds(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
