<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
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
}
