<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\PresentationSelection;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CopySourceTiming
{
    private function __construct(public float $origin, public float $duration)
    {
    }

    /** @param array<string, mixed> $probe */
    public static function fromProbe(array $probe, PresentationSelection $selection): self
    {
        $video = $audio = null;
        foreach ($probe['streams'] ?? [] as $stream) {
            if (($stream['index'] ?? null) === $selection->videoStreamIndex) {
                $video = $stream;
            }
            if ($selection->audioStreamIndex !== null && ($stream['index'] ?? null) === $selection->audioStreamIndex) {
                $audio = $stream;
            }
        }
        if (($video['codec_type'] ?? null) !== 'video' || ($video['codec_name'] ?? null) !== 'h264'
            || ($selection->audioStreamIndex !== null && (($audio['codec_type'] ?? null) !== 'audio' || ($audio['codec_name'] ?? null) !== 'aac'))) {
            throw new RuntimeException('The selected source streams no longer support copy delivery.');
        }
        $origin = null;
        foreach ($probe['packets'] ?? [] as $packet) {
            if (($packet['stream_index'] ?? null) === $selection->videoStreamIndex
                && is_numeric($packet['pts_time'] ?? null) && str_contains($packet['flags'] ?? '', 'K')) {
                $origin = (float) $packet['pts_time'];
                break;
            }
        }
        if ($origin === null || !is_finite($origin) || $origin < 0) {
            throw new RuntimeException('The source video has no verified initial keyframe.');
        }
        $end = null;
        $tag = $video['tags']['DURATION'] ?? null;
        if (is_string($tag) && preg_match('/^(\d+):([0-5]\d):([0-5]\d(?:\.\d+)?)$/D', $tag, $parts) === 1) {
            $end = (float) $parts[1] * 3600 + (float) $parts[2] * 60 + (float) $parts[3];
        } elseif (is_numeric($video['duration'] ?? null) && is_numeric($video['start_time'] ?? null)) {
            $end = (float) $video['start_time'] + (float) $video['duration'];
        } elseif (is_numeric($probe['format']['duration'] ?? null)) {
            $end = (float) $probe['format']['duration'];
        }
        if ($end === null || !is_finite($end) || $end <= $origin) {
            throw new RuntimeException('The source video has no verified duration.');
        }
        return new self($origin, $end - $origin);
    }

    /** @param list<array<string, mixed>> $frames */
    public function targetDuration(array $frames): float
    {
        $previous = $this->origin;
        $gap = 0.0;
        foreach ($frames as $frame) {
            if (!is_numeric($frame['best_effort_timestamp_time'] ?? null)) {
                continue;
            }
            $time = (float) $frame['best_effort_timestamp_time'];
            $gap = max($gap, $time - $previous);
            $previous = $time;
        }
        $gap = max($gap, $this->origin + $this->duration - $previous);
        return max(1.0, ceil(min($this->duration, 6.0 + $gap)));
    }

    /** @param list<array<string, mixed>> $packets */
    public function boundary(array $packets, float $position): float
    {
        $target = $this->origin + $position;
        $boundary = null;
        $previous = null;
        foreach ($packets as $packet) {
            if (!is_numeric($packet['pts_time'] ?? $packet['best_effort_timestamp_time'] ?? null)
                || (!str_contains($packet['flags'] ?? '', 'K') && ($packet['key_frame'] ?? null) !== 1)) {
                continue;
            }
            $time = (float) ($packet['pts_time'] ?? $packet['best_effort_timestamp_time']);
            if (is_finite($time) && $time >= $this->origin && $time <= $target + 0.000001) {
                if ($boundary === null || $time > $boundary) {
                    $previous = $boundary;
                    $boundary = $time;
                } elseif ($time < $boundary && ($previous === null || $time > $previous)) {
                    $previous = $time;
                }
            }
        }
        // AAC packets may begin just after a video keyframe. Keep one earlier GOP
        // so a cursor on or near that keyframe has audio pre-roll without retiming.
        return $previous ?? $boundary ?? throw new RuntimeException('No source keyframe was found for the requested position.');
    }
}
