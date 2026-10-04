<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

final class AudioPreferencePayload
{
    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    public static function valid(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true,
            'bands' => array_fill(0, 10, ['gain' => 0, 'q' => 1]),
            'preset' => 'FLAT',
            'compressionEnabled' => false,
            'compressorThreshold' => -24,
            'compressorRatio' => 4,
            'compressorKnee' => 10,
            'compressorAttack' => 3,
            'compressorRelease' => 250,
            'masterGain' => 0,
            'normalizationEnabled' => false,
            'targetLufs' => -14,
            'visualizerMode' => 'spectrum',
            'stereoEnabled' => false,
            'stereoWidth' => 1,
            'stereoMode' => 'normal',
            'crossfeedEnabled' => false,
            'crossfeedPreset' => 'normal',
            'loudnessContourEnabled' => false,
            'chainOrder' => ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain'],
        ], $overrides);
    }
}
