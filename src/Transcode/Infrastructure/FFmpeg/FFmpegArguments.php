<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;

/** Converts trusted domain codec options into literal arguments. */
final class FFmpegArguments
{
    /** @return list<string> */
    public static function subtitleExtraction(string $sourcePath, int $streamIndex, string $outputPath, ?float $sourceTimestampOrigin = null): array
    {
        if ($streamIndex < 0 || ($sourceTimestampOrigin !== null && (!is_finite($sourceTimestampOrigin) || $sourceTimestampOrigin < 0.0))) {
            throw new \InvalidArgumentException('Invalid subtitle stream index or source timestamp origin.');
        }
        $arguments = ['/usr/bin/ffmpeg', '-nostdin', '-y', '-threads', '2'];
        if ($sourceTimestampOrigin !== null) {
            array_push($arguments, '-copyts', '-itsoffset', sprintf('%.6f', -$sourceTimestampOrigin));
        }
        array_push($arguments, '-i', $sourcePath, '-map', '0:' . $streamIndex,
            '-vn', '-an', '-c:s', 'webvtt', '-f', 'webvtt', $outputPath);
        return $arguments;
    }

    /** @return list<string> */
    public static function flags(string $flags): array
    {
        preg_match_all('/"[^"]*"|\S+/', $flags, $matches);
        return array_map(static fn (string $token): string => $token[0] === '"' ? substr($token, 1, -1) : $token, $matches[0]);
    }

    /** @return list<string> */
    public static function input(EncoderProfile $profile): array
    {
        $arguments = [];
        if ($profile->isHardware()) {
            array_push($arguments, '-hwaccel', $profile->hwaccelMethod);
            if ($profile->hwaccelDevice !== '') {
                array_push($arguments, '-hwaccel_device', $profile->hwaccelDevice);
            }
            if ($profile->hwaccelOutputFormat !== '') {
                array_push($arguments, '-hwaccel_output_format', $profile->hwaccelOutputFormat);
            }
        }
        if ($profile->decoder !== '') {
            array_push($arguments, '-c:v', $profile->decoder);
        }
        return $arguments;
    }
}
