<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

final class FFprobeArguments
{
    /** @return list<string> */
    public static function video(string $sourcePath, string $ffprobePath = '/usr/bin/ffprobe'): array
    {
        return [$ffprobePath, '-threads', '2', '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_packets', '-read_intervals', '%+#128',
            '-show_entries', 'packet=stream_index,pts_time,flags', $sourcePath];
    }
}
