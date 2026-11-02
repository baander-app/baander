<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\AudioStreamInfo;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\EncodePresentationIdentity;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\TestCase;

final class EncodePresentationIdentityTest extends TestCase
{
    public function testStableIdentityTracksActualEncodingInputs(): void
    {
        $probe = $this->probe();
        $encoder = $this->encoder();
        $derive = static fn(string $hash = 'source', ?VideoProbeResult $source = null, ?QualityTier $tier = null, ?AudioProfile $audio = null, array $languages = ['en'], ?SegmentEncoder $settings = null) => EncodePresentationIdentity::derive($hash, $source ?? $probe, $tier ?? QualityTier::p720(), $audio ?? AudioProfile::streamingStereo(), $languages, $settings ?? $encoder);
        $selection = $derive();
        self::assertSame($selection->canonicalKey(), $derive()->canonicalKey());
        self::assertSame(3, $selection->videoStreamIndex);
        self::assertSame(7, $selection->audioStreamIndex);
        foreach ([$derive(hash: 'other'), $derive(source: $this->probe(8)), $derive(tier: QualityTier::p1080()), $derive(audio: AudioProfile::mobileMono()), $derive(languages: ['fr']), $derive(settings: $this->encoder('libx264'))] as $changed) {
            self::assertNotSame($selection->canonicalKey(), $changed->canonicalKey());
        }
    }

    public function testUnverifiedStreamIndexCannotQualifyAnArtifact(): void
    {
        $this->expectException(TranscodeStartupUnavailableException::class);
        EncodePresentationIdentity::derive('source', $this->probe(null), QualityTier::p720(), AudioProfile::streamingStereo(), [], $this->encoder());
    }

    private function probe(?int $audioIndex = 7): VideoProbeResult
    {
        return new VideoProbeResult(12, 1920, 1080, 24, null, null, 'yuv420p', 5000000, 'h264', 2, 'aac', 48000, false,
            [new AudioStreamInfo('en', 'aac', 2, 48000, 128000, 'English', true, 'stereo', $audioIndex)], videoStreamIndex: 3);
    }

    private function encoder(string $codec = 'libx265'): SegmentEncoder
    {
        return new SegmentEncoder(new MediaExecutionPolicy(new DeploymentProfile('full')), $this->createStub(FFmpegPortInterface::class), $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($codec));
    }
}
