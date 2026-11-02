<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioProbeResult
{
    public function __construct(
        public string $containerFormat,
        public int $streamIndex,
        public string $codec,
        public ?int $channels = null,
        public ?int $sampleRate = null,
        public ?int $bitrate = null,
        public ?float $duration = null,
    ) {
        if ($containerFormat === '' || $streamIndex < 0 || $codec === '' || $codec === 'unknown') {
            throw new \UnexpectedValueException('Audio probe requires an identified container, codec and absolute stream index.');
        }
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

        return new self(
            (string) ($raw['format']['format_name'] ?? ''),
            $index,
            (string) ($selected['codec_name'] ?? ''),
            $positive($selected['channels'] ?? null),
            $positive($selected['sample_rate'] ?? null),
            $positive($selected['bit_rate'] ?? null),
            is_numeric($selected['duration'] ?? null) && (float) $selected['duration'] > 0 ? (float) $selected['duration'] : null,
        );
    }
}
