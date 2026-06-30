<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Service;

use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use PHPUnit\Framework\TestCase;

class AudioProcessingRulesTest extends TestCase
{
    public function testLoudnessFilterReturnsCorrectLuftsForEachStandard(): void
    {
        foreach (LoudnessStandard::cases() as $standard) {
            $filter = AudioProcessingRules::loudnessFilter($standard);
            $this->assertStringContainsString(
                sprintf('I=%s', $standard->targetLufs()),
                $filter,
                "Filter for {$standard->value} should contain target LUFS",
            );
            $this->assertStringContainsString('TP=-1', $filter);
        }
    }

    public function testLoudnessFilterWithMeasuredValues(): void
    {
        $measured = [
            'input_i' => -25.3,
            'input_tp' => -2.1,
            'input_lra' => 8.5,
            'input_thresh' => -33.0,
            'target_offset' => 0.5,
        ];

        $filter = AudioProcessingRules::loudnessFilter(LoudnessStandard::EbuR128, $measured);

        $this->assertStringContainsString('measured_I=-25.3', $filter);
        $this->assertStringContainsString('measured_TP=-2.1', $filter);
        $this->assertStringContainsString('linear=true', $filter);
    }

    public function testDownmixFilterFor51ReturnsDolbyCoefficients(): void
    {
        $filter = AudioProcessingRules::downmixFilter(6);

        $this->assertStringContainsString('pan=stereo', $filter);
        // ITU-R BS.775-3 coefficients with corrected LFE contribution
        $this->assertStringContainsString('FL=0.3694*FC', $filter);
        $this->assertStringContainsString('0.3694*FL', $filter);
        $this->assertStringContainsString('0.1847*BL', $filter);
        $this->assertStringContainsString('0.0739*LFE', $filter);
    }

    public function testDownmixFilterFor71ReturnsDolbyCoefficients(): void
    {
        $filter = AudioProcessingRules::downmixFilter(8);

        $this->assertStringContainsString('pan=stereo', $filter);
        // ITU-R BS.775-3 coefficients with corrected LFE contribution
        $this->assertStringContainsString('FL=0.2612*FC', $filter);
        $this->assertStringContainsString('0.2612*FL', $filter);
        $this->assertStringContainsString('SL', $filter);
        $this->assertStringContainsString('BL', $filter);
        $this->assertStringContainsString('0.0739*LFE', $filter);
    }

    public function testDownmixFilterForStereoReturnsEmpty(): void
    {
        $filter = AudioProcessingRules::downmixFilter(2);
        $this->assertSame('', $filter);
    }

    public function testDownmixFilterForMonoReturnsEmpty(): void
    {
        $filter = AudioProcessingRules::downmixFilter(1);
        $this->assertSame('', $filter);
    }

    public function testDrcFilterReturnsAcompressor(): void
    {
        $filter = AudioProcessingRules::drcFilter(4.0, -24);

        $this->assertStringContainsString('acompressor', $filter);
        $this->assertStringContainsString('threshold=-24dB', $filter);
        $this->assertStringContainsString('ratio=4', $filter);
        $this->assertStringContainsString('attack=5', $filter);
        $this->assertStringContainsString('release=100', $filter);
    }

    public function testChannelLayoutFilter(): void
    {
        $this->assertSame('aformat=channel_layouts=mono', AudioProcessingRules::channelLayoutFilter(1));
        $this->assertSame('aformat=channel_layouts=stereo', AudioProcessingRules::channelLayoutFilter(2));
        $this->assertSame('aformat=channel_layouts=5.1(side)', AudioProcessingRules::channelLayoutFilter(6));
        $this->assertSame('aformat=channel_layouts=7.1(wide)', AudioProcessingRules::channelLayoutFilter(8));
    }

    public function testResampleFilterReturnsEmptyWhenRatesMatch(): void
    {
        $filter = AudioProcessingRules::resampleFilter(48000, 48000);
        $this->assertSame('', $filter);
    }

    public function testResampleFilterReturnsSoxrWhenRatesDiffer(): void
    {
        $filter = AudioProcessingRules::resampleFilter(44100, 48000);

        $this->assertStringContainsString('aresample=resampler=soxr', $filter);
        $this->assertStringContainsString('osr=48000', $filter);
    }

    public function testCodecOptionsForAac(): void
    {
        $profile = AudioProfile::streamingStereo();
        $options = AudioProcessingRules::codecOptions($profile);

        $this->assertStringContainsString('-c:a aac', $options);
        $this->assertStringContainsString('-b:a 128k', $options);
        // -q:a is a no-op when -b:a is already set; no longer emitted
    }

    public function testCodecOptionsForOpus(): void
    {
        $profile = AudioProfile::opusStereo();
        $options = AudioProcessingRules::codecOptions($profile);

        $this->assertStringContainsString('-c:a libopus', $options);
        $this->assertStringContainsString('-b:a 96k', $options);
        $this->assertStringContainsString('-application audio', $options);
    }

    public function testRecommendedBitrate(): void
    {
        $this->assertSame(32_000, AudioProcessingRules::recommendedBitrate(1, 'mobile'));
        $this->assertSame(64_000, AudioProcessingRules::recommendedBitrate(2, 'mobile'));
        $this->assertSame(128_000, AudioProcessingRules::recommendedBitrate(2, 'streaming'));
        $this->assertSame(256_000, AudioProcessingRules::recommendedBitrate(6, 'streaming'));
    }
}
