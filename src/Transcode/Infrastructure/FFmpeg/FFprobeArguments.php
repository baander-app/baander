<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

/**
 * The probes name every entry they read, so no tag a file carries is printed unless a parser
 * reads it: one large tag would otherwise fill the probe's bounded output.
 */
final class FFprobeArguments
{
    /** The entries AudioProbeResult reads. */
    private const string AUDIO_ENTRIES = 'format=format_name,duration,start_time'
        . ':stream=index,codec_type,codec_name,channels,sample_rate,bit_rate,duration'
        . ':stream_disposition=default';

    /**
     * The entries VideoProbeResult and CopySourceTiming read. The Matroska `DURATION` stream tag
     * gives a film's end, `language` and `title` name its tracks, and a chapter keeps its title.
     */
    private const string VIDEO_ENTRIES = 'format=format_name,duration,start_time'
        . ':stream=index,codec_type,codec_name,width,height,avg_frame_rate,r_frame_rate,pix_fmt,color_primaries,'
        . 'color_transfer,color_space,color_range,field_order,bit_rate,has_b_frames,time_base,start_pts,start_time,'
        . 'duration_ts,duration,channels,sample_rate,channel_layout'
        . ':stream_disposition=default,attached_pic,forced'
        . ':stream_tags=DURATION,language,title'
        . ':chapter=start_time,end_time:chapter_tags=title'
        . ':packet=stream_index,pts_time,flags';

    /** @return list<string> */
    public static function audio(string $sourcePath, string $ffprobePath = '/usr/bin/ffprobe'): array
    {
        return [$ffprobePath, '-threads', '2', '-v', 'error', '-print_format', 'json',
            '-show_entries', self::AUDIO_ENTRIES, ...FFmpegArguments::inputClause($sourcePath, FFmpegInputKind::LibraryAudio)];
    }

    /** @return list<string> */
    public static function video(string $sourcePath, string $ffprobePath = '/usr/bin/ffprobe'): array
    {
        return self::videoProbe($sourcePath, FFmpegInputKind::LibraryVideo, $ffprobePath);
    }

    /**
     * The probe of a worker's own `manifest.mpd`, which reads the same entries as a
     * library video probe through the DASH demuxer.
     *
     * @return list<string>
     */
    public static function publishedPresentation(string $manifestPath, string $ffprobePath = '/usr/bin/ffprobe'): array
    {
        return self::videoProbe($manifestPath, FFmpegInputKind::PublishedPresentation, $ffprobePath);
    }

    /** @return list<string> */
    private static function videoProbe(string $path, FFmpegInputKind $kind, string $ffprobePath): array
    {
        return [$ffprobePath, '-threads', '2', '-v', 'error', '-print_format', 'json',
            '-show_entries', self::VIDEO_ENTRIES, '-read_intervals', '%+#128', ...FFmpegArguments::inputClause($path, $kind)];
    }
}
