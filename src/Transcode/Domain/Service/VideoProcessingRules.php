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
        $forceKeyFrames = sprintf('-force_key_frames "expr:gte(t,n_forced*%d)"', self::SEGMENT_DURATION);

        return match ($encoder) {
            'libx265' => sprintf(
                '-c:v libx265 -tag:v hvc1 -pix_fmt yuv420p -g %1$d -keyint_min %1$d %2$s'
                . ' -x265-params "log-level=error:no-sao=1:no-deblock=1:no-scenecut=1:keyint=%1$d:min-keyint=%1$d"',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'libx264' => sprintf(
                '-c:v libx264 -tag:v avc1 -pix_fmt yuv420p -profile:v high -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'hevc_nvenc' => sprintf(
                '-c:v hevc_nvenc -tag:v hvc1 -pix_fmt yuv420p -profile:v main -preset p4 -spatial-aq 1 -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'h264_nvenc' => sprintf(
                '-c:v h264_nvenc -tag:v avc1 -pix_fmt yuv420p -profile:v high -preset p4 -spatial-aq 1 -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'libsvtav1' => sprintf(
                '-c:v libsvtav1 -pix_fmt yuv420p -preset 6 -g %1$d -keyint_min %1$d %2$s -svtav1-params "fast-decode=1:tune=0:keyint=%1$d"',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            // --- New VAAPI encoders ---
            'hevc_vaapi' => sprintf(
                '-c:v hevc_vaapi -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'h264_vaapi' => sprintf(
                '-c:v h264_vaapi -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            // --- New VideoToolbox encoders ---
            'hevc_videotoolbox' => sprintf(
                '-c:v hevc_videotoolbox -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'h264_videotoolbox' => sprintf(
                '-c:v h264_videotoolbox -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            // --- New QSV encoders ---
            'hevc_qsv' => sprintf(
                '-c:v hevc_qsv -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d -lookahead 0 %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'h264_qsv' => sprintf(
                '-c:v h264_qsv -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d -lookahead 0 %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            // --- New AMF encoders ---
            'hevc_amf' => sprintf(
                '-c:v hevc_amf -tag:v hvc1 -profile:v main -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            'h264_amf' => sprintf(
                '-c:v h264_amf -tag:v avc1 -profile:v high -g %1$d -keyint_min %1$d %2$s',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
            // --- Default fallback ---
            default => sprintf(
                '-c:v libx265 -tag:v hvc1 -pix_fmt yuv420p -g %1$d -keyint_min %1$d %2$s'
                . ' -x265-params "log-level=error:no-sao=1:no-deblock=1:no-scenecut=1:keyint=%1$d:min-keyint=%1$d"',
                self::GOP_SIZE,
                $forceKeyFrames,
            ),
        };
    }

    public static function initSegmentFlags(string $encoder): string
    {
        return self::codecFlags($encoder);
    }

    /**
     * Map an encoder name to the RFC 6381 codec string used in HLS/DASH manifests.
     */
    public static function codecRfc6381(string $encoder, int $height = 1080): string
    {
        return match ($encoder) {
            'libx265', 'hevc_nvenc', 'hevc_vaapi', 'hevc_videotoolbox', 'hevc_qsv', 'hevc_amf' => match (true) {
                $height <= 480 => 'hvc1.1.6.L93.B0',
                $height <= 720 => 'hvc1.1.6.L120.B0',
                $height <= 1080 => 'hvc1.1.6.L150.B0',
                $height <= 2160 => 'hvc1.1.6.L186.B0',
                default => 'hvc1.1.6.L186.B0',
            },
            'libx264', 'h264_nvenc', 'h264_vaapi', 'h264_videotoolbox', 'h264_qsv', 'h264_amf' => 'avc1.64001f',
            'libsvtav1' => 'av01.0.05M.08',
            default => 'hvc1.1.6.L93.B0',
        };
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
