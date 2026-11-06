<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\PresentationSelection;
use InvalidArgumentException;

/** Builds a copy-only fragmented MP4 presentation with its source timing intact. */
final class CopySegmentArguments
{
    /**
     * A stream copy is bound by disk, not by an encoder, so left alone it publishes a
     * whole movie before the session subscriber's lead throttle looks again. The input
     * is read in one burst of this many source seconds, then at the read rate below,
     * so the lead over the playhead starts under the throttle's 72-second pause mark
     * and keeps regrowing after it resumes at 36 seconds.
     */
    public const int INITIAL_BURST_SECONDS = 48;
    public const float READ_RATE = 2.0;

    public function __construct(private readonly string $ffmpegPath = '/usr/bin/ffmpeg')
    {
    }

    /** @return list<string> */
    public function build(string $sourcePath, PresentationSelection $selection, string $outputDirectory, float $sourceBoundary = 0.0): array
    {
        if ($selection->mode !== 'copy' || $selection->outputContainer !== 'mp4' || $selection->videoStreamIndex === null
            || !is_finite($sourceBoundary) || $sourceBoundary < 0) {
            throw new InvalidArgumentException('A video copy requires selected streams, MP4 and a valid source boundary.');
        }
        $arguments = [$this->ffmpegPath, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-threads', '1', '-copyts'];
        if ($sourceBoundary > 0) {
            array_push($arguments, '-seek_timestamp', '1', '-ss', sprintf('%.6f', $sourceBoundary));
        }
        array_push(
            $arguments,
            '-itsoffset',
            sprintf('%.6f', -$sourceBoundary),
            '-readrate_initial_burst',
            (string) self::INITIAL_BURST_SECONDS,
            '-readrate',
            sprintf('%.1f', self::READ_RATE),
            '-i',
            $sourcePath,
            '-map',
            '0:' . $selection->videoStreamIndex,
        );
        if ($selection->audioStreamIndex !== null) {
            array_push($arguments, '-map', '0:' . $selection->audioStreamIndex);
        }
        array_push(
            $arguments,
            '-c',
            'copy',
            '-sn',
            '-dn',
            '-f',
            'dash',
            '-seg_duration',
            '6',
            '-dash_segment_type',
            'mp4',
            '-format_options',
            'movflags=+negative_cts_offsets',
            '-use_template',
            '1',
            '-use_timeline',
            '1',
            '-hls_playlist',
            '1',
            '-hls_master_name',
            'master.m3u8',
            '-adaptation_sets',
            $selection->audioStreamIndex === null ? 'id=0,streams=v' : 'id=0,streams=v id=1,streams=a',
            '-init_seg_name',
            'init-$RepresentationID$.mp4',
            '-media_seg_name',
            'track-$RepresentationID$-seg-$Number%05d$.m4s',
            rtrim($outputDirectory, '/') . '/manifest.mpd'
        );
        return $arguments;
    }
}
