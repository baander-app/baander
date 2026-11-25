<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Infrastructure\FFmpeg\CopySegmentArguments;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use PHPUnit\Framework\TestCase;

final class CopySegmentArgumentsTest extends TestCase
{
    public function testCopiesAbsoluteStreamsAndNormalizesToActualSourceBoundary(): void
    {
        $builder = new CopySegmentArguments();
        $selection = new PresentationSelection('copy', 'source-hash', 7, 2, 'mp4');
        $args = $builder->build('/source.mkv', $selection, '/output', 6.17);

        self::assertSame(['0:7', '0:2'], $this->values($args, '-map'));
        self::assertSame(['copy'], $this->values($args, '-c'));
        self::assertSame(['6.170000'], $this->values($args, '-ss'));
        self::assertSame(['-6.170000'], $this->values($args, '-output_ts_offset'));
        $input = array_search('-i', $args, true);
        self::assertIsInt($input);
        self::assertLessThan($input, array_search('-ss', $args, true), 'The boundary seek is an input option');
        self::assertGreaterThan($input, array_search('-output_ts_offset', $args, true), 'The boundary offset is an output option so the read burst starts at the boundary');
        foreach (['-vf', '-af', '-b:v', '-b:a', '-force_key_frames', '-shortest', '-r', '-itsoffset'] as $option) {
            self::assertNotContains($option, $args);
        }
        self::assertSame('/output/manifest.mpd', array_last($args));
    }

    public function testReadsTheSourceInOneBurstAndThenAtABoundedRateSoTheLeadThrottleGoverns(): void
    {
        $builder = new CopySegmentArguments();
        $selection = new PresentationSelection('copy', 'source-hash', 0, 1, 'mp4');
        $args = $builder->build('/source.mkv', $selection, '/output');

        self::assertSame(['48'], $this->values($args, '-readrate_initial_burst'));
        self::assertSame(['2.0'], $this->values($args, '-readrate'));
        $input = array_search('-i', $args, true);
        self::assertIsInt($input);
        self::assertLessThan($input, array_search('-readrate_initial_burst', $args, true), 'The burst is an input option');
        self::assertLessThan($input, array_search('-readrate', $args, true), 'The read rate is an input option');
        self::assertLessThan(TranscodeSessionSubscriber::PAUSE_LEAD_SECONDS, CopySegmentArguments::INITIAL_BURST_SECONDS, 'The burst stays under the lead throttle pause mark');
        self::assertGreaterThan(TranscodeSessionSubscriber::RESUME_LEAD_SECONDS, CopySegmentArguments::INITIAL_BURST_SECONDS, 'The burst reaches past the throttle resume mark so the first window is usable at once');
        self::assertGreaterThan(1.0, CopySegmentArguments::READ_RATE, 'The lead regrows after the throttle resumes');
    }

    public function testVideoWithoutAudioMapsOnlySelectedVideo(): void
    {
        $builder = new CopySegmentArguments();
        $selection = new PresentationSelection('copy', 'source-hash', 3, null, 'mp4');
        $args = $builder->build('/source.mkv', $selection, '/output', 3.25);

        self::assertSame(['0:3'], $this->values($args, '-map'));
        self::assertSame(['id=0,streams=v'], $this->values($args, '-adaptation_sets'));
        self::assertSame(['avc1'], $this->values($args, '-tag:v'));
        self::assertSame([], $this->values($args, '-tag:a'));
    }

    public function testTheSourceOpensOnlyAsALibraryVideoContainerWithThePinnedOptionOrder(): void
    {
        $args = new CopySegmentArguments()->build(
            '/library/Holiday Clip (2021).avi',
            new PresentationSelection('copy', 'source-hash', 0, 1, 'mp4'),
            '/output',
            6.0,
        );

        self::assertSame([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-threads', '1', '-copyts',
            '-seek_timestamp', '1', '-ss', '6.000000', '-readrate_initial_burst', '48', '-readrate', '2.0',
            '-format_whitelist', 'mov,matroska,avi,asf', '-protocol_whitelist', 'file', '-i', '/library/Holiday Clip (2021).avi',
            '-map', '0:0', '-map', '0:1', '-c', 'copy', '-tag:v', 'avc1', '-tag:a', 'mp4a', '-sn', '-dn',
            '-f', 'dash', '-seg_duration', '6', '-dash_segment_type', 'mp4', '-format_options', 'movflags=+negative_cts_offsets',
            '-use_template', '1', '-use_timeline', '1', '-hls_playlist', '1', '-hls_master_name', 'master.m3u8',
            '-adaptation_sets', 'id=0,streams=v id=1,streams=a', '-init_seg_name', 'init-$RepresentationID$.mp4',
            '-media_seg_name', 'track-$RepresentationID$-seg-$Number%05d$.m4s', '-output_ts_offset', '-6.000000',
            '/output/manifest.mpd',
        ], $args);
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function values(array $arguments, string $option): array
    {
        return array_map(static fn(int $index): string => $arguments[$index + 1], array_keys($arguments, $option, true));
    }
}
