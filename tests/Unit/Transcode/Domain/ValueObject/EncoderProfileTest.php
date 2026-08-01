<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use PHPUnit\Framework\TestCase;

final class EncoderProfileTest extends TestCase
{
    public function testSoftwareProfileDefaults(): void
    {
        $profile = EncoderProfile::software();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertSame('libx265', $profile->encoder);
        $this->assertFalse($profile->isHardware());
    }

    public function testSoftwareProfileWithCustomEncoder(): void
    {
        $profile = EncoderProfile::software('libx264');
        $this->assertSame('libx264', $profile->encoder);
        $this->assertFalse($profile->isHardware());
    }

    public function testFromEncoderNameHardware(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $this->assertSame(HardwareAccelerator::Nvenc, $profile->accelerator);
        $this->assertSame('hevc_nvenc', $profile->encoder);
        $this->assertTrue($profile->isHardware());
    }

    public function testFromEncoderNameSoftware(): void
    {
        $profile = EncoderProfile::fromEncoderName('libx265');
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertFalse($profile->isHardware());
    }

    public function testHwaccelInputFlagsForSoftware(): void
    {
        $profile = EncoderProfile::software();
        $this->assertSame('', $profile->hwaccelInputFlags());
    }

    public function testHwaccelInputFlagsForNvenc(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel cuda', $flags);
        $this->assertStringContainsString('-hwaccel_output_format cuda', $flags);
    }

    public function testHwaccelInputFlagsForVaapi(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel vaapi', $flags);
        $this->assertStringContainsString("-hwaccel_device '/dev/dri/renderD128'", $flags);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $flags);
    }

    public function testDecoderFlagsEmptyByDefault(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $this->assertSame('', $profile->decoderFlags());
    }

    public function testWithDecoderForSource(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $resolved = $profile->withDecoderForSource('h264');
        $this->assertSame('h264_cuvid', $resolved->decoder);
        $this->assertSame('-c:v h264_cuvid', $resolved->decoderFlags());
        // Original unchanged (immutable)
        $this->assertSame('', $profile->decoder);
    }

    public function testWithDecoderForSourceReturnsSameInstanceIfNoDecoder(): void
    {
        $profile = EncoderProfile::software();
        $resolved = $profile->withDecoderForSource('h264');
        $this->assertSame($profile->encoder, $resolved->encoder);
        $this->assertSame('', $resolved->decoder);
    }

    public function testJsonSerializeRoundTrip(): void
    {
        $original = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $data = $original->jsonSerialize();
        $restored = EncoderProfile::fromArray($data);

        $this->assertSame($original->accelerator, $restored->accelerator);
        $this->assertSame($original->encoder, $restored->encoder);
        $this->assertSame($original->hwaccelDevice, $restored->hwaccelDevice);
    }

    public function testEquals(): void
    {
        $a = EncoderProfile::fromEncoderName('hevc_nvenc');
        $b = EncoderProfile::fromEncoderName('hevc_nvenc');
        $this->assertTrue($a->equals($b));

        $c = EncoderProfile::fromEncoderName('hevc_vaapi');
        $this->assertFalse($a->equals($c));
    }
}
