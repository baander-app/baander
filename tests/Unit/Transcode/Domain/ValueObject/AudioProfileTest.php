<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use PHPUnit\Framework\TestCase;

class AudioProfileTest extends TestCase
{
    public function testFactoryMethodsReturnCorrectBitrateChannelsCodec(): void
    {
        $mobile = AudioProfile::mobileMono();
        $this->assertSame('mobile_mono', $mobile->name);
        $this->assertSame(32_000, $mobile->bitrate);
        $this->assertSame('1.0', $mobile->channelLayout);
        $this->assertSame(1, $mobile->channelCount);
        $this->assertSame('aac', $mobile->codec);

        $streaming = AudioProfile::streamingStereo();
        $this->assertSame('streaming_stereo', $streaming->name);
        $this->assertSame(128_000, $streaming->bitrate);
        $this->assertSame('2.0', $streaming->channelLayout);
        $this->assertSame(2, $streaming->channelCount);

        $surround = AudioProfile::streaming51();
        $this->assertSame('streaming_5.1', $surround->name);
        $this->assertSame(256_000, $surround->bitrate);
        $this->assertSame('5.1', $surround->channelLayout);
        $this->assertSame(6, $surround->channelCount);
    }

    public function testMobileMonoHasDrcEnabledWithAggressiveSettings(): void
    {
        $profile = AudioProfile::mobileMono();

        $this->assertTrue($profile->applyDrc);
        $this->assertSame(6.0, $profile->drcRatio);
        $this->assertSame(-20, $profile->drcThreshold);
        $this->assertSame(LoudnessStandard::Mobile, $profile->loudnessStandard);
    }

    public function testMobileStereoHasDownmixAndDrcEnabled(): void
    {
        $profile = AudioProfile::mobileStereo();

        $this->assertTrue($profile->downmixSurround);
        $this->assertTrue($profile->applyDrc);
        $this->assertSame(4.0, $profile->drcRatio);
        $this->assertSame(LoudnessStandard::Mobile, $profile->loudnessStandard);
    }

    public function testHifiStereoHasDrcDisabledAndDialogueStandard(): void
    {
        $profile = AudioProfile::hifiStereo();

        $this->assertFalse($profile->applyDrc);
        $this->assertSame(LoudnessStandard::Dialogue, $profile->loudnessStandard);
        $this->assertSame(256_000, $profile->bitrate);
    }

    public function testOpusStereoUsesOpusCodec(): void
    {
        $profile = AudioProfile::opusStereo();

        $this->assertSame('opus', $profile->codec);
        $this->assertSame(96_000, $profile->bitrate);
        $this->assertTrue($profile->downmixSurround);
    }

    public function testBroadcastProfilesUseEbuR128(): void
    {
        $stereo = AudioProfile::broadcastStereo();
        $this->assertSame(LoudnessStandard::EbuR128, $stereo->loudnessStandard);

        $surround = AudioProfile::broadcast51();
        $this->assertSame(LoudnessStandard::EbuR128, $surround->loudnessStandard);
        $this->assertSame(384_000, $surround->bitrate);
    }

    public function testChannelCountMatchesLayout(): void
    {
        $this->assertSame(1, AudioProfile::mobileMono()->channelCount);
        $this->assertSame(2, AudioProfile::streamingStereo()->channelCount);
        $this->assertSame(6, AudioProfile::streaming51()->channelCount);
    }

    public function testFromStringReturnsCorrectProfile(): void
    {
        $this->assertEquals(AudioProfile::mobileMono(), AudioProfile::fromString('mobile_mono'));
        $this->assertEquals(AudioProfile::streamingStereo(), AudioProfile::fromString('streaming_stereo'));
        $this->assertEquals(AudioProfile::opusStereo(), AudioProfile::fromString('opus_stereo'));
    }

    public function testFromStringWithUnknownProfileThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown audio profile: "nonexistent"');

        AudioProfile::fromString('nonexistent');
    }

    public function testEquals(): void
    {
        $this->assertTrue(AudioProfile::streamingStereo()->equals(AudioProfile::streamingStereo()));
        $this->assertFalse(AudioProfile::streamingStereo()->equals(AudioProfile::hifiStereo()));
    }

    public function testJsonSerialize(): void
    {
        $profile = AudioProfile::streaming51();
        $serialized = $profile->jsonSerialize();

        $this->assertSame('streaming_5.1', $serialized['name']);
        $this->assertSame('streaming', $serialized['loudnessStandard']);
        $this->assertSame(6, $serialized['channelCount']);
        $this->assertArrayHasKey('applyDrc', $serialized);
        $this->assertArrayHasKey('drcRatio', $serialized);
        $this->assertArrayHasKey('drcThreshold', $serialized);
    }
}
