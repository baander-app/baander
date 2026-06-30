<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Service;

use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;

final class AudioProcessingRules
{
    public const TRUE_PEAK_LIMIT = -1.0;

    /**
     * Two-pass loudnorm filter string with measured values.
     *
     * @param array{input_i?: float, input_tp?: float, input_lra?: float, input_thresh?: float, target_offset?: float} $measured
     */
    public static function loudnessFilter(LoudnessStandard $standard, array $measured = []): string
    {
        $target = $standard->targetLufs();

        if (empty($measured)) {
            return sprintf(
                'loudnorm=I=%s:TP=%s:LRA=11:print_format=json',
                $target,
                self::TRUE_PEAK_LIMIT,
            );
        }

        return sprintf(
            'loudnorm=I=%s:TP=%s:LRA=11:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
            $target,
            self::TRUE_PEAK_LIMIT,
            $measured['input_i'] ?? '0',
            $measured['input_tp'] ?? '0',
            $measured['input_lra'] ?? '0',
            $measured['input_thresh'] ?? '0',
            $measured['target_offset'] ?? '0',
        );
    }

    /**
     * Dolby surround downmix filter per ITU-R BS.775-3.
     *
     * Coefficients ensure per-channel sum ≤ 1.0 to prevent clipping.
     * LFE at ~0.07 (standard practice — full-range sub channel should
     * not dominate the stereo downmix).
     *
     * Returns empty string if source has no surround channels.
     */
    public static function downmixFilter(int $sourceChannels): string
    {
        if ($sourceChannels === 8) {
            // 7.1 → Stereo (ITU-R BS.775-3)
            // FL sum ≈ 0.97, FR sum ≈ 0.97
            return 'pan=stereo|'
                . 'FL=0.2612*FC+0.2612*FL+0.1847*SL+0.1847*BL+0.0739*LFE|'
                . 'FR=0.2612*FC+0.2612*FR+0.1847*SR+0.1847*BR+0.0739*LFE';
        }

        if ($sourceChannels === 6) {
            // 5.1 → Stereo (ITU-R BS.775-3)
            // FL sum ≈ 1.0, FR sum ≈ 1.0
            return 'pan=stereo|'
                . 'FL=0.3694*FC+0.3694*FL+0.1847*BL+0.0739*LFE|'
                . 'FR=0.3694*FC+0.3694*FR+0.1847*BR+0.0739*LFE';
        }

        return '';
    }

    /**
     * Dialogue enhancement filter — boosts centre-channel frequency range.
     *
     * Applies a gentle EQ boost around 1-4kHz (dialogue fundamental range)
     * to improve speech clarity after downmixing from surround to stereo.
     *
     * @param float $gainDb Boost in dB (default 3dB, max 6dB recommended)
     */
    public static function dialogueEnhancementFilter(float $gainDb = 3.0): string
    {
        $clamped = max(0.0, min(6.0, $gainDb));

        return sprintf(
            'equalizer=f=2000:t=q:w=1:g=%.1f',
            $clamped,
        );
    }

    /**
     * Dynamic range compression filter.
     *
     * @param float $ratio    Compression ratio (e.g. 4.0 = 4:1)
     * @param int   $thresholdDb Threshold in dB above which compression starts
     * @param int   $makeupDb  Makeup gain in dB (default 2dB to compensate for reduced peaks)
     */
    public static function drcFilter(float $ratio, int $thresholdDb, int $makeupDb = 2): string
    {
        return sprintf(
            'acompressor=threshold=%ddB:ratio=%.1f:attack=5:release=100:makeup=%d',
            $thresholdDb,
            $ratio,
            $makeupDb,
        );
    }

    /**
     * Channel layout enforcement filter.
     */
    public static function channelLayoutFilter(int $targetChannelCount): string
    {
        return match ($targetChannelCount) {
            1 => 'aformat=channel_layouts=mono',
            2 => 'aformat=channel_layouts=stereo',
            6 => 'aformat=channel_layouts=5.1(side)',
            8 => 'aformat=channel_layouts=7.1(wide)',
            default => 'aformat=channel_layouts=stereo',
        };
    }

    /**
     * Sample rate conversion filter using SoX.
     * Returns empty string if rates match.
     */
    public static function resampleFilter(int $sourceRate, int $targetRate): string
    {
        if ($sourceRate === $targetRate) {
            return '';
        }

        return sprintf('aresample=resampler=soxr:osr=%d', $targetRate);
    }

    /**
     * Codec-specific FFmpeg encoding options.
     */
    public static function codecOptions(AudioProfile $profile): string
    {
        $bitrate = (int) ($profile->bitrate / 1000);

        return match ($profile->codec) {
            'opus' => sprintf('-c:a libopus -b:a %dk -application audio', $bitrate),
            'ac3' => sprintf('-c:a ac3 -b:a %dk', $bitrate),
            default => sprintf('-c:a aac -b:a %dk', $bitrate),
        };
    }

    /**
     * Recommended bitrate for a given channel configuration.
     */
    public static function recommendedBitrate(int $channels, string $profile): int
    {
        return match ($profile) {
            'mobile' => $channels === 1 ? 32_000 : 64_000,
            'streaming' => $channels >= 6 ? 256_000 : 128_000,
            'broadcast' => $channels >= 6 ? 384_000 : 192_000,
            'hifi' => 256_000,
            default => 128_000,
        };
    }
}
