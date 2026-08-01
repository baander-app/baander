<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Domain\ValueObject\LoudnessStandard;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

interface FFmpegPortInterface
{
    public function probeVideo(string $sourcePath): VideoProbeResult;

    public function encodeInitSegment(
        string $sourcePath,
        QualityTier $qualityTier,
        string $outputPath,
    ): string;

    public function encodeSegment(
        string $sourcePath,
        float $startTime,
        float $duration,
        QualityTier $qualityTier,
        array $audioProfile,
        string $videoFilters,
        string $audioFilters,
        string $outputPath,
    ): void;

    /**
     * Run two-pass loudness analysis.
     *
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}
     */
    public function analyzeAudioLoudness(string $sourcePath, LoudnessStandard $standard): array;

    // --- Separate Audio Encoding ---

    /**
     * Encode an audio-only init segment (fMP4 moov box, no video).
     */
    public function encodeAudioInitSegment(
        string $sourcePath,
        array $audioProfile,
        string $outputPath,
    ): string;

    /**
     * Encode an audio-only media segment (fMP4, no video).
     */
    public function encodeAudioSegment(
        string $sourcePath,
        float $startTime,
        float $duration,
        array $audioProfile,
        string $audioFilters,
        string $outputPath,
    ): void;

    /**
     * Extract a subtitle track as WebVTT.
     */
    public function extractSubtitleTrack(
        string $sourcePath,
        string $language,
        string $outputPath,
    ): void;
}
