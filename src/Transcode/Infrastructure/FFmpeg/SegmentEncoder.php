<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class SegmentEncoder
{
    private const float SEGMENT_DURATION = 6.0; // seconds per CMAF segment

    public function __construct(
        private readonly FFmpegPortInterface $ffmpeg,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly EncoderProfile $encoderProfile = new EncoderProfile(
            HardwareAccelerator::None,
            'libx265',
            '',
            '',
            '',
            '',
        ),
        private readonly float $bitrateMultiplier = 1.0,
    ) {
    }

    /**
     * Encode the init segment for a job.
     */
    public function encodeInit(TranscodeJob $job, string $sourcePath): string
    {
        $tier = QualityTier::fromString($job->getQualityTierName());
        $initPath = $this->storage->resolveInitSegmentPath($job->getVideoId(), $tier);

        return $this->ffmpeg->encodeInitSegment($sourcePath, $tier, $initPath);
    }

    /**
     * Build video filters based on probe result and quality tier.
     */
    public function buildVideoFilters(VideoProbeResult $probe, QualityTier $tier): string
    {
        $builder = VideoFilterBuilder::create($this->encoderProfile->accelerator);

        if ($probe->isInterlaced) {
            $builder->deinterlace();
        }

        $method = VideoProcessingRules::resolveToneMapMethod($probe, $tier);
        $builder->tonemap($probe, $method, ColorSpace::bt709());
        $builder->scale($tier);

        if ($probe->framerate > 0 && $probe->framerate > 30.0) {
            $builder->framerate(30.0);
        }

        return $builder->build();
    }

    /**
     * Build audio filters based on probe result, session audio profile, and optional measured loudness.
     */
    /**
     * @param array<string, mixed> $measuredLoudness
     */
    public function buildAudioFilters(
        VideoProbeResult $probe,
        TranscodeSession $session,
        array $measuredLoudness = [],
    ): string {
        $profile = $session->getAudioProfile();

        $builder = AudioFilterBuilder::create();
        $builder->downmix($probe, $profile);
        $builder->dialogueEnhancement($probe, $profile);
        $builder->loudness($profile->loudnessStandard, $measuredLoudness);
        $builder->drc($profile);
        $builder->channelLayout($profile);
        $builder->resample($probe, $profile);

        return $builder->build();
    }

    /**
     * Encode a single segment.
     */
    public function encodeSegment(
        TranscodeJob $job,
        TranscodeSession $session,
        string $sourcePath,
        int $segmentIndex,
        float $startTime,
        string $videoFilters,
        string $audioFilters,
    ): string {
        $tier = QualityTier::fromString($job->getQualityTierName());
        $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $segmentIndex);

        $this->ffmpeg->encodeSegment(
            sourcePath: $sourcePath,
            startTime: $startTime,
            duration: self::SEGMENT_DURATION,
            qualityTier: $tier,
            audioProfile: $session->getAudioProfile()->jsonSerialize(),
            videoFilters: $videoFilters,
            audioFilters: $audioFilters,
            outputPath: $outputPath,
        );

        return $outputPath;
    }

    /**
     * Apply bitrate multiplier to a quality tier for hardware encoding.
     * Returns a new QualityTier with adjusted bitrates.
     */
    public function applyBitrateMultiplier(QualityTier $tier): QualityTier
    {
        if ($this->bitrateMultiplier === 1.0) {
            return $tier;
        }

        return new QualityTier(
            name: $tier->name,
            height: $tier->height,
            width: $tier->width,
            videoBitrate: (int) round($tier->videoBitrate * $this->bitrateMultiplier),
            maxBitrate: (int) round($tier->maxBitrate * $this->bitrateMultiplier),
            bufferSize: (int) round($tier->bufferSize * $this->bitrateMultiplier),
            codec: $tier->codec,
            rfc6381Codec: $tier->rfc6381Codec,
        );
    }

    public static function getSegmentDuration(): float
    {
        return self::SEGMENT_DURATION;
    }
}
