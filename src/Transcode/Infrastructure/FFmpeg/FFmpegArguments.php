<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;

/** Converts trusted domain codec options into literal arguments. */
final class FFmpegArguments
{
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
