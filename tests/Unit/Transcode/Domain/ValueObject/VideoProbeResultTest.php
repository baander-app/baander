<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoProbeResultTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function movieWithLongerAudio(): array
    {
        return ['format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'start_time' => '0.000000', 'duration' => '18.000000'],
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'time_base' => '1/12800',
                    'start_pts' => 0, 'start_time' => '0.000000', 'duration_ts' => 179200, 'duration' => '14.000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'duration' => '18.000000'],
            ]];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('selectedVideoEndpoints')]
    public function testSelectedVideoEndpointUsesItsOwnMetadataAndInputClock(array $raw, float $expected): void
    {
        $probe = VideoProbeResult::fromProbeOutput($raw);
        foreach ([$probe, VideoProbeResult::fromSerialized($probe->jsonSerialize())] as $result) {
            self::assertSame($expected, $result->duration);
            self::assertSame($probe->sourceTimestampOrigin, $result->sourceTimestampOrigin);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, float}> */
    public static function selectedVideoEndpoints(): iterable
    {
        $raw = self::movieWithLongerAudio();
        yield 'MOV video ends before audio' => [$raw, 14.0];
        $raw['streams'][0]['start_pts'] = 51200;
        $raw['streams'][0]['start_time'] = '4.000000';
        yield 'video starts after audio' => [$raw, 18.0];
        $raw['format']['start_time'] = '4.000000';
        $raw['format']['duration'] = '22.000000';
        yield 'both tracks start after zero' => [$raw, 14.0];
        $raw = self::movieWithLongerAudio();
        unset($raw['streams'][0]['start_time'], $raw['streams'][0]['duration']);
        yield 'rational timing without textual seconds' => [$raw, 14.0];
        $raw = self::movieWithLongerAudio();
        unset($raw['streams'][0]['start_pts'], $raw['streams'][0]['duration_ts']);
        yield 'textual timing without rational values' => [$raw, 14.0];
        $raw = self::movieWithLongerAudio();
        $raw['streams'][0]['time_base'] = '1/3';
        $raw['streams'][0]['duration_ts'] = 40;
        $raw['streams'][0]['duration'] = '13.333333';
        yield 'rational value survives decimal rounding' => [$raw, 40 / 3];
        $raw = self::movieWithLongerAudio();
        $raw['streams'][] = ['codec_type' => 'video', 'duration' => '40', 'disposition' => ['default' => 1]];
        array_unshift($raw['streams'], ['codec_type' => 'video', 'duration' => '90', 'disposition' => ['attached_pic' => 1]]);
        $raw['format']['duration'] = '90';
        yield 'artwork and longer alternate video do not determine endpoint' => [$raw, 14.0];
        $raw = self::movieWithLongerAudio();
        $raw['format']['format_name'] = 'matroska,webm';
        $raw['streams'][0]['duration'] = '18';
        $raw['streams'][0]['duration_ts'] = 230400;
        $raw['streams'][0]['tags']['DURATION'] = '00:00:14.000000000';
        yield 'Matroska tag overrides inferred container span' => [$raw, 14.0];
        unset($raw['streams'][0]['duration'], $raw['streams'][0]['duration_ts']);
        yield 'Matroska without stream duration' => [$raw, 14.0];
        $raw['streams'][0]['tags']['DURATION'] = '00:00:14.040000000';
        yield 'Matroska fractional presentation tail' => [$raw, 14.04];
        $raw['streams'][0]['tags']['DURATION'] = '01:02:03.004';
        $raw['format']['duration'] = '4000';
        yield 'Matroska clock hours and minutes' => [$raw, 3723.004];
        $raw = self::anchoredMovie();
        $raw['format']['duration'] = '42';
        $raw['streams'][0]['duration'] = '42';
        $raw['streams'][0]['tags']['DURATION'] = '00:00:34.000000000';
        yield 'Matroska endpoint subtracts verified origin once' => [$raw, 30.0];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('unqualifiedVideoEndpoints')]
    public function testUnqualifiedVideoEndpointKeepsTheExistingEstimate(array $raw, float $expected = 18.0): void
    {
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertSame($expected, $probe->duration);
        self::assertSame($expected, VideoProbeResult::fromSerialized($probe->jsonSerialize())->duration);
    }

    /** @return iterable<string, array{array<string, mixed>, float}|array{array<string, mixed>}> */
    public static function unqualifiedVideoEndpoints(): iterable
    {
        foreach ([null, '-1', INF, 'unknown', []] as $start) {
            $raw = self::movieWithLongerAudio();
            $raw['format']['start_time'] = $start;
            yield 'unqualified ordinary start '.json_encode($start, JSON_PARTIAL_OUTPUT_ON_ERROR) => [$raw];
        }
        $raw = self::movieWithLongerAudio();
        unset($raw['format']['start_time']);
        yield 'missing ordinary start' => [$raw];
        $raw = self::movieWithLongerAudio();
        unset($raw['streams'][0]['start_pts'], $raw['streams'][0]['start_time']);
        yield 'missing selected video start' => [$raw];
        $raw = self::movieWithLongerAudio();
        unset($raw['streams'][0]['duration_ts'], $raw['streams'][0]['duration']);
        yield 'missing selected video span' => [$raw];
        $raw = self::movieWithLongerAudio();
        $raw['streams'][0] = array_replace($raw['streams'][0], ['time_base' => '1/1',
            'start_pts' => '1000000000000000000', 'start_time' => '1000000000000000128',
            'duration_ts' => 14]);
        yield 'large magnitude does not expand decimal tolerance' => [$raw];
        foreach ([
            ['duration_ts', 'bad'], ['duration_ts', '99999999999999999999999'], ['duration_ts', 0],
            ['duration_ts', -1], ['time_base', '1/0'], ['time_base', '-1/12800'], ['time_base', '1/12800/2'],
            ['time_base', null], ['time_base', '99999999999999999999999/1'],
            ['duration', '13.999999'], ['duration', null], ['duration', INF], ['duration', 'bad'],
            ['start_time', '0.000001'], ['start_pts', 'bad'],
        ] as [$key, $value]) {
            $raw = self::movieWithLongerAudio();
            $raw['streams'][0][$key] = $value;
            yield $key.'='.json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR) => [$raw];
        }
        foreach (['14', '00:60:14', '00:00:60', '00:00:-1', '00:00:14.0000000000',
            ' 00:00:14.000', '00:00:14.000 ', '00:00:0e1', '00:00:00', str_repeat('9', 30).':00:00', null, []] as $tag) {
            $raw = self::movieWithLongerAudio();
            $raw['format']['format_name'] = 'matroska,webm';
            $raw['streams'][0]['tags']['DURATION'] = $tag;
            yield 'unqualified tag '.json_encode($tag) => [$raw];
        }
        foreach (['mpegts', 'unknown', null, []] as $format) {
            $raw = self::movieWithLongerAudio();
            $raw['format']['format_name'] = $format;
            yield 'unqualified format '.json_encode($format) => [$raw];
        }
        $raw = self::movieWithLongerAudio();
        $raw['format']['start_time'] = '4';
        yield 'selected MOV start before ordinary origin' => [$raw];
        $raw = self::movieWithLongerAudio();
        $raw['format']['start_time'] = '14';
        yield 'endpoint at ordinary origin' => [$raw];
        $raw['format']['start_time'] = '15';
        yield 'endpoint before ordinary origin' => [$raw];
        $raw = self::anchoredMovie();
        $raw['format']['duration'] = '42';
        $raw['streams'][0]['tags']['DURATION'] = '00:00:04.000';
        yield 'invalid endpoint retains verified-origin estimate' => [$raw, 38.0];
    }

    public function testSerializedAndDirectDurationsAreNotResolvedAgain(): void
    {
        $probe = VideoProbeResult::fromSerialized(['duration' => 18.0, 'sourceTimestampOrigin' => 4.0]);
        self::assertSame(18.0, $probe->duration);
        $direct = new VideoProbeResult(18.0, 64, 64, 25, null, null, null, 0, 'h264', 2, 'aac', 48000, false,
            sourceTimestampOrigin: 4.0);
        self::assertSame(18.0, $direct->duration);
    }

    /** @param list<bool> $defaults */
    #[DataProvider('sourceAudioDefaults')]
    public function testSourceAudioSelectionKeepsOrdinalsAndCanonicalAttributes(array $defaults, ?int $expected): void
    {
        $streams = [];
        foreach ($defaults as $ordinal => $default) {
            $streams[] = ['index' => 4 + $ordinal * 3, 'codec_type' => 'audio',
                'codec_name' => $ordinal === 0 ? 'aac' : 'pcm_s16le',
                'channels' => $ordinal === 0 ? 1 : 6, 'channel_layout' => $ordinal === 0 ? 'mono' : '5.1(side)',
                'sample_rate' => $ordinal === 0 ? '44100' : '48000', 'disposition' => ['default' => $default],
                'tags' => ['language' => $ordinal === 0 ? 'eng' : 'dan']];
        }
        $probe = VideoProbeResult::fromProbeOutput(['streams' => $streams]);
        $serialized = $probe->jsonSerialize();
        $serialized['audioChannels'] = 9;
        $serialized['audioCodec'] = 'stale';
        $serialized['audioSampleRate'] = 12345;
        $serialized['audioChannelLayout'] = 'stale';
        $direct = new VideoProbeResult(0, 0, 0, 0, null, null, null, 0, 'unknown',
            9, 'stale', 12345, false, $probe->audioStreams, audioChannelLayout: 'stale');
        foreach ([$probe, VideoProbeResult::fromSerialized($serialized), $direct] as $candidate) {
            self::assertSame($expected, $candidate->selectedAudioStreamOrdinal());
            self::assertSame($defaults, array_map(static fn ($stream) => $stream->isDefault, $candidate->audioStreams));
            self::assertSame(array_column($streams, 'codec_name'), array_map(static fn ($stream) => $stream->codec, $candidate->audioStreams));
            if ($expected === null) {
                self::assertNull($candidate->selectedAudioStream());
                self::assertFalse($candidate->hasAudio());
                continue;
            }
            $selected = $candidate->selectedAudioStream();
            self::assertNotNull($selected);
            self::assertSame($candidate->audioStreams[$expected], $selected);
            self::assertSame($expected === 0 ? 'eng' : 'dan', $selected->language);
            self::assertSame($selected->channels, $candidate->audioChannels);
            self::assertSame($selected->codec, $candidate->audioCodec);
            self::assertSame($selected->sampleRate, $candidate->audioSampleRate);
            self::assertSame($selected->channelLayout, $candidate->audioChannelLayout);
            self::assertNotSame($streams[$expected]['index'], $expected);
        }
    }

    /** @return iterable<string, array{list<bool>, ?int}> */
    public static function sourceAudioDefaults(): iterable
    {
        yield 'later default' => [[false, true], 1];
        yield 'no defaults' => [[false, false], 0];
        yield 'multiple defaults' => [[true, true], 0];
        yield 'later multiple defaults' => [[false, true, true], 1];
        yield 'no audio' => [[], null];
    }

    /** @return array<string, mixed> */
    private static function anchoredMovie(): array
    {
        return ['format' => ['format_name' => 'matroska,webm', 'start_time' => '12', 'duration' => '34'],
            'streams' => [['index' => 2, 'codec_type' => 'video', 'codec_name' => 'h264', 'has_b_frames' => 0, 'r_frame_rate' => '1/10']],
            'packets' => [['stream_index' => 2, 'pts_time' => '4', 'flags' => 'K_']]];
    }

    public function testVerifiedOriginNormalizesDurationOnceAcrossSerialization(): void
    {
        $probe = VideoProbeResult::fromProbeOutput(self::anchoredMovie());
        foreach ([$probe, VideoProbeResult::fromSerialized($probe->jsonSerialize())] as $result) {
            self::assertSame(4.0, $result->sourceTimestampOrigin);
            self::assertSame(30.0, $result->duration);
        }
        self::assertNull(VideoProbeResult::fromSerialized(['duration' => 34])->sourceTimestampOrigin);
    }

    public function testMatchedPositiveOriginStillNormalizesContainerEnd(): void
    {
        $raw = self::anchoredMovie();
        $raw['format']['start_time'] = '4';
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertSame(4.0, $probe->sourceTimestampOrigin);
        self::assertSame(30.0, $probe->duration);
    }

    public function testZeroPacketOriginOverridesLaterReportedOrigin(): void
    {
        $raw = self::anchoredMovie();
        $raw['packets'][0]['pts_time'] = '0';
        $raw['format']['duration'] = '30';
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertSame(0.0, $probe->sourceTimestampOrigin);
        self::assertSame(30.0, $probe->duration);
    }

    public function testOriginEvidenceUsesSelectedMovieAndItsFirstPacket(): void
    {
        $raw = self::anchoredMovie();
        array_unshift($raw['streams'], ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]]);
        array_unshift($raw['packets'], ['stream_index' => 0, 'pts_time' => '0', 'flags' => 'K_']);
        $raw['packets'][] = ['stream_index' => 2, 'pts_time' => '0', 'flags' => 'K_'];
        self::assertSame(4.0, VideoProbeResult::fromProbeOutput($raw)->sourceTimestampOrigin);
        $raw['packets'][1]['flags'] = '__';
        self::assertNull(VideoProbeResult::fromProbeOutput($raw)->sourceTimestampOrigin);
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('unverifiedOriginEvidence')]
    public function testUnverifiedEvidenceKeepsDurationAndNoOrigin(array $raw): void
    {
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertNull($probe->sourceTimestampOrigin);
        self::assertSame((float) ($raw['format']['duration'] ?? 0), $probe->duration);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unverifiedOriginEvidence(): iterable
    {
        foreach ([
            'other format' => ['format', 'format_name', 'mov,mp4'],
            'missing format' => ['format', 'format_name', null],
            'bad format name' => ['format', 'format_name', []],
            'missing start' => ['format', 'start_time', null],
            'malformed start' => ['format', 'start_time', 'unknown'],
            'nonfinite start' => ['format', 'start_time', INF],
            'start before packet' => ['format', 'start_time', '3'],
            'end at packet' => ['format', 'duration', '4'],
            'nonfinite end' => ['format', 'duration', INF],
            'malformed end' => ['format', 'duration', 'unknown'],
            'missing end' => ['format', 'duration', null],
            'missing B frame count' => ['video', 'has_b_frames', null],
            'B frames' => ['video', 'has_b_frames', 2],
            'other codec' => ['video', 'codec_name', 'hevc'],
            'ordinary rate' => ['video', 'r_frame_rate', '1/1'],
            'unknown rate' => ['video', 'r_frame_rate', '0/0'],
            'negative rate' => ['video', 'r_frame_rate', '-1/10'],
            'malformed rate' => ['video', 'r_frame_rate', []],
            'malformed rational numerator' => ['video', 'r_frame_rate', '1garbage/10'],
            'malformed rational denominator' => ['video', 'r_frame_rate', '1/10garbage'],
            'extra rational part' => ['video', 'r_frame_rate', '1/10/2'],
            'malformed decimal rate' => ['video', 'r_frame_rate', '0.1garbage'],
            'nonfinite rate' => ['video', 'r_frame_rate', '1e999'],
            'missing stream index' => ['video', 'index', null],
            'negative stream index' => ['video', 'index', -1],
            'untyped stream' => ['video', 'codec_type', null],
            'wrong packet stream' => ['packet', 'stream_index', 1],
            'missing PTS' => ['packet', 'pts_time', null],
            'negative PTS' => ['packet', 'pts_time', '-1'],
            'nonfinite PTS' => ['packet', 'pts_time', INF],
            'malformed PTS' => ['packet', 'pts_time', []],
            'not key' => ['packet', 'flags', '__'],
            'discarded key' => ['packet', 'flags', 'KD'],
            'missing flags' => ['packet', 'flags', null],
        ] as $name => [$part, $key, $value]) {
            $raw = self::anchoredMovie();
            if ($part === 'format') {
                $raw['format'][$key] = $value;
            } elseif ($part === 'video') {
                $raw['streams'][0][$key] = $value;
            } else {
                $raw['packets'][0][$key] = $value;
            }
            yield $name => [$raw];
        }
        $raw = self::anchoredMovie();
        $raw['packets'] = [];
        yield 'missing selected packet in prefix' => [$raw];
        $raw['packets'] = 'invalid';
        yield 'malformed packet list' => [$raw];
        $raw = self::anchoredMovie();
        $raw['packets'] = [2 => $raw['packets'][0]];
        yield 'nonlist packet evidence' => [$raw];
        $raw = self::anchoredMovie();
        $selected = $raw['packets'][0];
        $raw['packets'] = array_fill(0, 128, ['stream_index' => 1, 'pts_time' => '4', 'flags' => 'K_']);
        $raw['packets'][] = $selected;
        yield 'selected packet beyond bounded prefix' => [$raw];
        $raw['packets'][0] = $selected;
        yield 'oversized prefix with selected packet inside bound' => [$raw];
        $raw['packets'] = array_fill(0, 128, ['stream_index' => 1, 'pts_time' => '4', 'flags' => 'K_']);
        yield 'missing selected packet across full bounded prefix' => [$raw];
        $raw = self::anchoredMovie();
        $raw['format']['start_time'] = '0';
        $raw['packets'][0]['pts_time'] = '0';
        yield 'matched zero origin' => [$raw];
    }

    public function testFirstSelectedMoviePacketAtPrefixBoundaryQualifies(): void
    {
        $raw = self::anchoredMovie();
        $selected = $raw['packets'][0];
        $raw['packets'] = array_fill(0, 127, ['stream_index' => 1, 'pts_time' => '4', 'flags' => 'K_']);
        $raw['packets'][] = $selected;
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertSame(4.0, $probe->sourceTimestampOrigin);
        self::assertSame(30.0, $probe->duration);
    }

    public function testMissingPtsCannotUseDecodeTimestampAsOrigin(): void
    {
        $raw = self::anchoredMovie();
        unset($raw['packets'][0]['pts_time']);
        $raw['packets'][0]['dts_time'] = '4';
        self::assertNull(VideoProbeResult::fromProbeOutput($raw)->sourceTimestampOrigin);
    }

    public function testMalformedSerializedOriginsDoNotChangeNormalizedDuration(): void
    {
        foreach ([-1, INF, -INF, NAN, [], 'unknown'] as $origin) {
            $probe = VideoProbeResult::fromSerialized(['duration' => 30.0, 'sourceTimestampOrigin' => $origin]);
            self::assertNull($probe->sourceTimestampOrigin);
            self::assertSame(30.0, $probe->duration);
        }
    }

    public function testFromProbeOutputWithCompleteData(): void
    {
        $raw = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'r_frame_rate' => '24000/1001',
                    'color_primaries' => 'bt709',
                    'color_transfer' => 'bt709',
                    'color_space' => 'bt709',
                    'color_range' => 'tv',
                    'pix_fmt' => 'yuv420p',
                    'bit_rate' => '5000000',
                    'codec_name' => 'hevc',
                ],
                [
                    'codec_type' => 'audio',
                    'channels' => 6,
                    'codec_name' => 'aac',
                    'sample_rate' => '48000',
                ],
            ],
            'format' => [
                'duration' => '5400.5',
            ],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);

        $this->assertSame(5400.5, $result->duration);
        $this->assertSame(1920, $result->width);
        $this->assertSame(1080, $result->height);
        $this->assertSame(6, $result->audioChannels);
        $this->assertSame('aac', $result->audioCodec);
        $this->assertSame(48_000, $result->audioSampleRate);
        $this->assertSame('hevc', $result->videoCodec);
        $this->assertFalse($result->isHdr());
    }

    /** @param list<array<string, mixed>> $streams */
    #[DataProvider('movieStreamOrders')]
    public function testMovieMetadataExcludesAttachedPictures(array $streams): void
    {
        $probe = VideoProbeResult::fromProbeOutput(['streams' => $streams, 'format' => ['duration' => '14']]);
        foreach ([$probe, VideoProbeResult::fromSerialized($probe->jsonSerialize())] as $result) {
            self::assertSame(64, $result->width);
            self::assertSame(48, $result->height);
            self::assertSame(10.0, $result->framerate);
            self::assertSame('h264', $result->videoCodec);
            self::assertSame(14.0, $result->duration);
            self::assertFalse($result->isHdr());
            self::assertSame('yuv420p', $result->pixFmt);
            self::assertSame('tv', $result->colorRange);
            self::assertSame('bt709', $result->colorSpace?->transfer);
            self::assertTrue($result->hasAudio());
            self::assertSame('dan', $result->audioStreams[0]->language);
            self::assertSame(9, $result->subtitleStreams[0]->index);
        }
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function movieStreamOrders(): iterable
    {
        $movie = ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 64, 'height' => 48,
            'r_frame_rate' => '10/1', 'pix_fmt' => 'yuv420p', 'color_range' => 'tv',
            'color_primaries' => 'bt709', 'color_transfer' => 'bt709', 'color_space' => 'bt709'];
        $cover = ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'width' => 32, 'height' => 32,
            'r_frame_rate' => '90000/1', 'color_transfer' => 'smpte2084',
            'disposition' => ['attached_pic' => 1, 'default' => 1]];
        $audio = ['codec_type' => 'audio', 'codec_name' => 'aac', 'index' => 7, 'channels' => 2, 'tags' => ['language' => 'dan']];
        $subtitle = ['codec_type' => 'subtitle', 'codec_name' => 'mov_text', 'index' => 9];
        yield 'cover before movie' => [[$cover, $movie, $audio, $subtitle]];
        yield 'cover after movie' => [[$movie, $cover, $audio, $subtitle]];
        yield 'audio before cover and movie' => [[$audio, $cover, $movie, $subtitle]];
        yield 'ordinary video without dispositions' => [[$movie, $audio, $subtitle]];
        $alternative = array_replace($movie, ['width' => 128, 'disposition' => ['default' => 1]]);
        yield 'first ordinary video stays selected' => [[$cover, $movie, $alternative, $audio, $subtitle]];
    }

    /** @param list<array<string, mixed>> $streams */
    #[DataProvider('streamsWithoutMovieVideo')]
    public function testTypedNonMovieStreamsDoNotBecomeMovieMetadata(array $streams): void
    {
        $probe = VideoProbeResult::fromProbeOutput(['streams' => $streams]);
        self::assertSame(0, $probe->width);
        self::assertSame(0, $probe->height);
        self::assertSame(0.0, $probe->framerate);
        self::assertSame('unknown', $probe->videoCodec);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function streamsWithoutMovieVideo(): iterable
    {
        $cover = ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'width' => 32, 'height' => 32,
            'r_frame_rate' => '90000/1', 'disposition' => ['attached_pic' => 1]];
        $audio = ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2];
        yield 'artwork only' => [[$cover]];
        yield 'audio only' => [[$audio]];
        yield 'audio with artwork' => [[$audio, $cover]];
    }

    public function testFromProbeOutputWithMissingStreamDataUsesDefaults(): void
    {
        $raw = [];

        $result = VideoProbeResult::fromProbeOutput($raw);

        $this->assertSame(0.0, $result->duration);
        $this->assertSame(0, $result->width);
        $this->assertSame(0, $result->height);
        $this->assertSame(0.0, $result->framerate);
        $this->assertSame('unknown', $result->videoCodec);
        $this->assertSame(2, $result->audioChannels);
        $this->assertSame('unknown', $result->audioCodec);
        $this->assertSame(48_000, $result->audioSampleRate);
        $this->assertNotNull($result->colorSpace);
        $this->assertSame('bt709', $result->colorSpace->primaries);
        $this->assertNull($result->pixFmt);
    }

    public function testIsHdrDetectsPqColorTransfer(): void
    {
        $raw = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_primaries' => 'bt2020',
                    'color_transfer' => 'smpte2084',
                ],
            ],
            'format' => [],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $this->assertTrue($result->isHdr());
    }

    public function testIsHdrDetectsHlgColorTransfer(): void
    {
        $raw = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_transfer' => 'arib-std-b67',
                ],
            ],
            'format' => [],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $this->assertTrue($result->isHdr());
    }

    public function testIsHdrIsFalseForSdr(): void
    {
        $raw = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_primaries' => 'bt709',
                    'color_transfer' => 'bt709',
                ],
            ],
            'format' => [],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $this->assertFalse($result->isHdr());
    }

    #[DataProvider('wideGamutSdrTransfers')]
    public function testWideGamutSdrMetadataSurvivesProbeAndSerialization(?string $transfer): void
    {
        $stream = ['codec_type' => 'video', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc'];
        if ($transfer !== null) {
            $stream['color_transfer'] = $transfer;
        }
        $probe = VideoProbeResult::fromProbeOutput(['streams' => [$stream]]);
        $serialized = $probe->jsonSerialize();
        self::assertFalse($serialized['isHdr']);

        $legacy = $serialized;
        unset($legacy['colorSpace']);
        $legacy['isHdr'] = true;
        foreach ([$probe, VideoProbeResult::fromSerialized($serialized), VideoProbeResult::fromSerialized($legacy)] as $result) {
            self::assertFalse($result->isHdr());
            self::assertSame(['primaries' => 'bt2020', 'transfer' => $transfer ?? 'bt709', 'matrix' => 'bt2020nc'],
                $result->colorSpace?->jsonSerialize());
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function wideGamutSdrTransfers(): iterable
    {
        foreach (['bt709', 'bt2020-10', 'bt2020-12', 'unknown', ''] as $transfer) {
            yield $transfer === '' ? 'empty' : $transfer => [$transfer];
        }
        yield 'missing' => [null];
    }

    public function testIsSurround(): void
    {
        $stereo = VideoProbeResult::fromProbeOutput([
            'streams' => [
                ['codec_type' => 'audio', 'channels' => 2],
            ],
            'format' => [],
        ]);
        $this->assertFalse($stereo->isSurround());

        $surround = VideoProbeResult::fromProbeOutput([
            'streams' => [
                ['codec_type' => 'audio', 'channels' => 6],
            ],
            'format' => [],
        ]);
        $this->assertTrue($surround->isSurround());
    }

    public function testIs51AndIs71(): void
    {
        $fiveOne = VideoProbeResult::fromProbeOutput([
            'streams' => [
                ['codec_type' => 'audio', 'channels' => 6],
            ],
            'format' => [],
        ]);
        $this->assertTrue($fiveOne->is51());
        $this->assertFalse($fiveOne->is71());

        $sevenOne = VideoProbeResult::fromProbeOutput([
            'streams' => [
                ['codec_type' => 'audio', 'channels' => 8],
            ],
            'format' => [],
        ]);
        $this->assertFalse($sevenOne->is51());
        $this->assertTrue($sevenOne->is71());
    }

    public function testFramerateParsingWithFraction(): void
    {
        $raw = [
            'streams' => [['codec_type' => 'video', 'r_frame_rate' => '30000/1001']],
            'format' => [],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $this->assertEqualsWithDelta(29.97, $result->framerate, 0.01);
    }

    public function testFramerateParsingWithInteger(): void
    {
        $raw = [
            'streams' => [['codec_type' => 'video', 'r_frame_rate' => '25']],
            'format' => [],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $this->assertSame(25.0, $result->framerate);
    }

    public function testJsonSerialize(): void
    {
        $raw = [
            'streams' => [
                ['width' => 1280, 'height' => 720, 'r_frame_rate' => '30', 'codec_name' => 'hevc', 'color_transfer' => 'smpte2084'],
                ['channels' => 2, 'codec_name' => 'aac', 'sample_rate' => '48000'],
            ],
            'format' => ['duration' => '100.0'],
        ];

        $result = VideoProbeResult::fromProbeOutput($raw);
        $serialized = $result->jsonSerialize();

        $this->assertSame(720, $serialized['height']);
        $this->assertTrue($serialized['isHdr']);
        $this->assertArrayHasKey('audioChannels', $serialized);
        // New nested format
        $this->assertArrayHasKey('colorSpace', $serialized);
        $this->assertIsArray($serialized['colorSpace']);
        // Legacy backward-compat keys
        $this->assertArrayHasKey('colorPrimaries', $serialized);
        $this->assertArrayHasKey('colorTransfer', $serialized);
        $this->assertArrayHasKey('colorMatrix', $serialized);
    }

    public function testFromSerializedWithLegacyFormat(): void
    {
        $data = [
            'duration' => 100.0,
            'width' => 1920,
            'height' => 1080,
            'framerate' => 24.0,
            'colorPrimaries' => 'bt2020',
            'colorTransfer' => 'smpte2084',
            'colorMatrix' => 'bt2020nc',
            'colorRange' => 'tv',
            'pixFmt' => 'yuv420p10le',
            'videoBitrate' => 20_000_000,
            'videoCodec' => 'hevc',
            'audioChannels' => 6,
            'audioCodec' => 'truehd',
            'audioSampleRate' => 48_000,
            'isHdr' => true,
            'isInterlaced' => false,
        ];

        $result = VideoProbeResult::fromSerialized($data);

        $this->assertSame(100.0, $result->duration);
        $this->assertSame(1920, $result->width);
        $this->assertNotNull($result->colorSpace);
        $this->assertSame('bt2020', $result->colorSpace->primaries);
        $this->assertSame('smpte2084', $result->colorSpace->transfer);
        $this->assertTrue($result->isHdr());
    }

    public function testFromSerializedWithNestedFormat(): void
    {
        $data = [
            'duration' => 100.0,
            'width' => 1920,
            'height' => 1080,
            'framerate' => 24.0,
            'colorSpace' => [
                'primaries' => 'bt2020',
                'transfer' => 'smpte2084',
                'matrix' => 'bt2020nc',
            ],
            'colorRange' => 'tv',
            'pixFmt' => 'yuv420p10le',
            'videoBitrate' => 20_000_000,
            'videoCodec' => 'hevc',
            'audioChannels' => 6,
            'audioCodec' => 'truehd',
            'audioSampleRate' => 48_000,
            'isHdr' => true,
            'isInterlaced' => false,
        ];

        $result = VideoProbeResult::fromSerialized($data);

        $this->assertNotNull($result->colorSpace);
        $this->assertSame('bt2020', $result->colorSpace->primaries);
        $this->assertTrue($result->isHdr());
    }
    public function testStandardFieldOrdersRecognizeOnlyInterlacedVideo(): void
    {
        foreach (['tt' => true, 'bb' => true, 'tb' => true, 'bt' => true, 'progressive' => false, 'unknown' => false] as $fieldOrder => $expected) {
            $probe = VideoProbeResult::fromProbeOutput(['streams' => [['codec_type' => 'video', 'field_order' => $fieldOrder]]]);
            self::assertSame($expected, $probe->isInterlaced, $fieldOrder);
        }
    }

    public function testAudioPresenceAndLayoutSurviveSerialization(): void
    {
        $silent = VideoProbeResult::fromProbeOutput(['streams' => [['codec_type' => 'video']]]);
        self::assertFalse($silent->hasAudio());
        $probe = VideoProbeResult::fromProbeOutput(['streams' => [
            ['codec_type' => 'video'],
            ['codec_type' => 'audio', 'channels' => 6, 'channel_layout' => '5.1(side)'],
        ]]);
        $restored = VideoProbeResult::fromSerialized($probe->jsonSerialize());
        self::assertTrue($restored->hasAudio());
        self::assertSame('5.1(side)', $restored->audioChannelLayout);
        self::assertSame('5.1(side)', $restored->audioStreams[0]->channelLayout);
    }

}
