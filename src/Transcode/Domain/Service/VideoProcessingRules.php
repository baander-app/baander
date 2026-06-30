<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Service;

use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\ToneMapMethod;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class VideoProcessingRules
{
    /**
     * GOP size for CMAF segment alignment.
     * At 30fps with 6s segments: 30 × 6 = 180 frames per GOP.
     * Ensures every segment boundary falls on a keyframe.
     */
    private const SEGMENT_DURATION = 6.0;
    private const TARGET_FPS = 30;
    private const GOP_SIZE = self::SEGMENT_DURATION * self::TARGET_FPS; // 180

    public static function codecFlags(string $encoder): string
    {
        return match ($encoder) {
            'libx265' => sprintf(
                '-c:v libx265 -tag:v hvc1 -pix_fmt yuv420p -g %1$d -keyint_min %1$d'
                . ' -x265-params "log-level=error:no-sao=1:no-deblock=1:no-scenecut=1:keyint=%1$d:min-keyint=%1$d"',
                self::GOP_SIZE,
            ),
            'hevc_nvenc' => sprintf(
                '-c:v hevc_nvenc -tag:v hvc1 -pix_fmt yuv420p -profile:v main -preset p4 -spatial-aq 1 -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            'h264_nvenc' => sprintf(
                '-c:v h264_nvenc -tag:v avc1 -pix_fmt yuv420p -profile:v high -preset p4 -spatial-aq 1 -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            'libsvtav1' => sprintf(
                '-c:v libsvtav1 -pix_fmt yuv420p -preset 6 -g %1$d -keyint_min %1$d -svtav1-params "fast-decode=1:tune=0:keyint=%1$d"',
                self::GOP_SIZE,
            ),
            // --- New VAAPI encoders ---
            'hevc_vaapi' => sprintf(
                '-c:v hevc_vaapi -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            'h264_vaapi' => sprintf(
                '-c:v h264_vaapi -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            // --- New VideoToolbox encoders ---
            'hevc_videotoolbox' => sprintf(
                '-c:v hevc_videotoolbox -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            'h264_videotoolbox' => sprintf(
                '-c:v h264_videotoolbox -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            // --- New QSV encoders ---
            'hevc_qsv' => sprintf(
                '-c:v hevc_qsv -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d -lookahead 0',
                self::GOP_SIZE,
            ),
            'h264_qsv' => sprintf(
                '-c:v h264_qsv -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d -lookahead 0',
                self::GOP_SIZE,
            ),
            // --- New AMF encoders ---
            'hevc_amf' => sprintf(
                '-c:v hevc_amf -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            'h264_amf' => sprintf(
                '-c:v h264_amf -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d',
                self::GOP_SIZE,
            ),
            // --- Default fallback ---
            default => sprintf(
                '-c:v libx265 -tag:v hvc1 -pix_fmt yuv420p -g %1$d -keyint_min %1$d'
                . ' -x265-params "log-level=error:no-sao=1:no-deblock=1:no-scenecut=1:keyint=%1$d:min-keyint=%1$d"',
                self::GOP_SIZE,
            ),
        };
    }

    public static function initSegmentFlags(string $encoder): string
    {
        return self::codecFlags($encoder);
    }

    /**
     * Determine the appropriate tone-map method for the given source and target.
     */
    public static function resolveToneMapMethod(VideoProbeResult $probe, QualityTier $tier): ToneMapMethod
    {
        if ($probe->colorSpace === null || !$probe->colorSpace->isHdr()) {
            return ToneMapMethod::None;
        }

        // HEVC main10 profile supports HDR passthrough
        if ($tier->codec === 'hvc1' && str_contains($tier->rfc6381Codec, 'L93')) {
            // Check if we want passthrough — for now default to tone-mapping
            // Future: make this configurable per-session
        }

        return ToneMapMethod::Hable;
    }
}
