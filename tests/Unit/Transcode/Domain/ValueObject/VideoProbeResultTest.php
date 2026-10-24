<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoProbeResultTest extends TestCase
{
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
