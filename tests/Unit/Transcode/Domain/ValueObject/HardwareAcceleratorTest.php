<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use PHPUnit\Framework\TestCase;

final class HardwareAcceleratorTest extends TestCase
{
    public function testAllCasesHaveHwaccelMethod(): void
    {
        foreach (HardwareAccelerator::cases() as $case) {
            if ($case === HardwareAccelerator::None) {
                $this->assertSame('', $case->ffmpegHwaccelMethod());
            } else {
                $this->assertNotEmpty($case->ffmpegHwaccelMethod());
            }
        }
    }

    public function testNvencReturnsCudaMethod(): void
    {
        $this->assertSame('cuda', HardwareAccelerator::Nvenc->ffmpegHwaccelMethod());
    }

    public function testVaapiRequiresDevicePath(): void
    {
        $this->assertTrue(HardwareAccelerator::Vaapi->requiresDevicePath());
        $this->assertFalse(HardwareAccelerator::Nvenc->requiresDevicePath());
        $this->assertFalse(HardwareAccelerator::None->requiresDevicePath());
    }

    public function testOnlyNvencSupportsHardwareTonemap(): void
    {
        foreach (HardwareAccelerator::cases() as $case) {
            $this->assertSame(
                $case === HardwareAccelerator::Nvenc,
                $case->supportsHardwareTonemap(),
                sprintf('%s tonemap assertion failed', $case->value),
            );
        }
    }

    public function testHevcEncoderNames(): void
    {
        $this->assertSame('libx265', HardwareAccelerator::None->hevcEncoder());
        $this->assertSame('hevc_nvenc', HardwareAccelerator::Nvenc->hevcEncoder());
        $this->assertSame('hevc_vaapi', HardwareAccelerator::Vaapi->hevcEncoder());
        $this->assertSame('hevc_videotoolbox', HardwareAccelerator::VideoToolbox->hevcEncoder());
        $this->assertSame('hevc_qsv', HardwareAccelerator::Qsv->hevcEncoder());
        $this->assertSame('hevc_amf', HardwareAccelerator::Amf->hevcEncoder());
    }

    public function testH264EncoderNames(): void
    {
        $this->assertSame('libx264', HardwareAccelerator::None->h264Encoder());
        $this->assertSame('h264_nvenc', HardwareAccelerator::Nvenc->h264Encoder());
        $this->assertSame('h264_vaapi', HardwareAccelerator::Vaapi->h264Encoder());
    }

    public function testDecoderForCodecNvenc(): void
    {
        $nvenc = HardwareAccelerator::Nvenc;
        $this->assertSame('h264_cuvid', $nvenc->decoderForCodec('h264'));
        $this->assertSame('hevc_cuvid', $nvenc->decoderForCodec('hevc'));
        $this->assertSame('av1_cuvid', $nvenc->decoderForCodec('av1'));
        $this->assertSame('', $nvenc->decoderForCodec('unknown'));
    }

    public function testDecoderForCodecQsv(): void
    {
        $qsv = HardwareAccelerator::Qsv;
        $this->assertSame('h264_qsv', $qsv->decoderForCodec('h264'));
        $this->assertSame('hevc_qsv', $qsv->decoderForCodec('hevc'));
        $this->assertSame('av1_qsv', $qsv->decoderForCodec('av1'));
    }

    public function testDecoderForCodecVaapiReturnsEmpty(): void
    {
        $this->assertSame('', HardwareAccelerator::Vaapi->decoderForCodec('h264'));
    }

    public function testDecoderForCodecNoneReturnsEmpty(): void
    {
        $this->assertSame('', HardwareAccelerator::None->decoderForCodec('h264'));
    }

    public function testFromEncoderName(): void
    {
        $this->assertSame(HardwareAccelerator::Nvenc, HardwareAccelerator::fromEncoderName('hevc_nvenc'));
        $this->assertSame(HardwareAccelerator::Vaapi, HardwareAccelerator::fromEncoderName('hevc_vaapi'));
        $this->assertSame(HardwareAccelerator::Qsv, HardwareAccelerator::fromEncoderName('hevc_qsv'));
        $this->assertSame(HardwareAccelerator::Amf, HardwareAccelerator::fromEncoderName('hevc_amf'));
        $this->assertSame(HardwareAccelerator::VideoToolbox, HardwareAccelerator::fromEncoderName('hevc_videotoolbox'));
        $this->assertSame(HardwareAccelerator::None, HardwareAccelerator::fromEncoderName('libx265'));
        $this->assertSame(HardwareAccelerator::None, HardwareAccelerator::fromEncoderName('unknown'));
    }

    public function testAmfUsesVaapiHwaccelMethod(): void
    {
        $this->assertSame('vaapi', HardwareAccelerator::Amf->ffmpegHwaccelMethod());
        $this->assertSame('vaapi', HardwareAccelerator::Amf->hwaccelOutputFormat());
    }
}
