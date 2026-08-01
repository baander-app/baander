<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Service;

use App\Transcode\Domain\Service\VideoProcessingRules;
use PHPUnit\Framework\TestCase;

final class VideoProcessingRulesTest extends TestCase
{
    //
    // codecFlags()
    //

    public function testCodecFlagsForLibx265IncludesGopAlignment(): void
    {
        $flags = VideoProcessingRules::codecFlags('libx265');

        $this->assertStringContainsString('-c:v libx265', $flags);
        $this->assertStringContainsString('-tag:v hvc1', $flags);
        $this->assertStringContainsString('-pix_fmt yuv420p', $flags);
        $this->assertStringContainsString('-g 180', $flags);
        $this->assertStringContainsString('-keyint_min 180', $flags);
        $this->assertStringContainsString('no-scenecut=1', $flags);
        $this->assertStringContainsString('keyint=180', $flags);
        $this->assertStringContainsString('min-keyint=180', $flags);
    }

    public function testCodecFlagsForHevcNvencUsesMainProfile(): void
    {
        $flags = VideoProcessingRules::codecFlags('hevc_nvenc');

        $this->assertStringContainsString('-c:v hevc_nvenc', $flags);
        $this->assertStringContainsString('-tag:v hvc1', $flags);
        $this->assertStringContainsString('-profile:v main', $flags);
        $this->assertStringContainsString('-preset p4', $flags);
        $this->assertStringContainsString('-g 180', $flags);
        $this->assertStringContainsString('-keyint_min 180', $flags);
        // main10 profile with yuv420p is incorrect; verify main is used
        $this->assertStringNotContainsString('main10', $flags);
    }

    public function testCodecFlagsForH264Nvenc(): void
    {
        $flags = VideoProcessingRules::codecFlags('h264_nvenc');

        $this->assertStringContainsString('-c:v h264_nvenc', $flags);
        $this->assertStringContainsString('-tag:v avc1', $flags);
        $this->assertStringContainsString('-profile:v high', $flags);
        $this->assertStringContainsString('-g 180', $flags);
    }

    public function testCodecFlagsForLibsvtav1(): void
    {
        $flags = VideoProcessingRules::codecFlags('libsvtav1');

        $this->assertStringContainsString('-c:v libsvtav1', $flags);
        $this->assertStringContainsString('-preset 6', $flags);
        $this->assertStringContainsString('-g 180', $flags);
        $this->assertStringContainsString('keyint=180', $flags);
    }

    public function testCodecFlagsDefaultFallsBackToLibx265(): void
    {
        $flags = VideoProcessingRules::codecFlags('unknown_encoder');

        $this->assertStringContainsString('-c:v libx265', $flags);
        $this->assertStringContainsString('-g 180', $flags);
    }

    public function testAllEncodersHaveGopAndKeyintMin(): void
    {
        $encoders = ['libx265', 'hevc_nvenc', 'h264_nvenc', 'libsvtav1'];

        foreach ($encoders as $encoder) {
            $flags = VideoProcessingRules::codecFlags($encoder);
            $this->assertStringContainsString('-g 180', $flags, "Encoder {$encoder} should have GOP alignment");
            $this->assertStringContainsString('-keyint_min 180', $flags, "Encoder {$encoder} should have keyint_min");
        }
    }

    //
    // New hardware encoder entries
    //

    public function testCodecFlagsForHevcVaapiIncludesGopAlignment(): void
    {
        $flags = VideoProcessingRules::codecFlags('hevc_vaapi');
        $this->assertStringContainsString('-c:v hevc_vaapi', $flags);
        $this->assertStringContainsString('-g 180', $flags);
        $this->assertStringContainsString('-tag:v hvc1', $flags);
        $this->assertStringNotContainsString('-pix_fmt', $flags);
    }

    public function testCodecFlagsForH264Vaapi(): void
    {
        $flags = VideoProcessingRules::codecFlags('h264_vaapi');
        $this->assertStringContainsString('-c:v h264_vaapi', $flags);
        $this->assertStringContainsString('-tag:v avc1', $flags);
    }

    public function testCodecFlagsForHevcVideoToolbox(): void
    {
        $flags = VideoProcessingRules::codecFlags('hevc_videotoolbox');
        $this->assertStringContainsString('-c:v hevc_videotoolbox', $flags);
        $this->assertStringNotContainsString('-preset', $flags);
    }

    public function testCodecFlagsForHevcQsv(): void
    {
        $flags = VideoProcessingRules::codecFlags('hevc_qsv');
        $this->assertStringContainsString('-c:v hevc_qsv', $flags);
        $this->assertStringContainsString('-lookahead 0', $flags);
    }

    public function testCodecFlagsForHevcAmf(): void
    {
        $flags = VideoProcessingRules::codecFlags('hevc_amf');
        $this->assertStringContainsString('-c:v hevc_amf', $flags);
        $this->assertStringContainsString('-tag:v hvc1', $flags);
    }

    public function testAllNewEncodersHaveGopAndKeyintMin(): void
    {
        foreach (['hevc_vaapi', 'h264_vaapi', 'hevc_videotoolbox', 'h264_videotoolbox',
                  'hevc_qsv', 'h264_qsv', 'hevc_amf', 'h264_amf'] as $encoder) {
            $flags = VideoProcessingRules::codecFlags($encoder);
            $this->assertStringContainsString('-g 180', $flags, "{$encoder} missing -g");
            $this->assertStringContainsString('-keyint_min 180', $flags, "{$encoder} missing -keyint_min");
        }
    }

    //
    // initSegmentFlags()
    //

    public function testInitSegmentFlagsDelegatesToCodecFlags(): void
    {
        $codec = VideoProcessingRules::codecFlags('libx265');
        $init = VideoProcessingRules::initSegmentFlags('libx265');

        $this->assertSame($codec, $init);
    }
}
