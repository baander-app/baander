<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\HLS;

use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use RuntimeException;

final class FMP4SegmentWriter
{
    public function __construct(
        private readonly string $ffmpegPath = '/usr/bin/ffmpeg',
        private readonly EncoderProfile $encoderProfile = new EncoderProfile(
            HardwareAccelerator::None,
            'libx265',
            '',
            '',
            '',
            '',
        ),
    ) {
    }

    public function encodeInitSegment(
        string $sourcePath,
        QualityTier $qualityTier,
        string $outputPath,
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $encoderFlags = VideoProcessingRules::initSegmentFlags($this->encoderProfile->encoder);
        $hwAccelFlags = $this->encoderProfile->hwaccelInputFlags();
        $decoderFlags = $this->encoderProfile->decoderFlags();

        $cmd = sprintf(
            '%s -y %s%s -i %s %s'
            . ' -an -t 0.001 -movflags +frag_keyframe+empty_moov+default_base_moof'
            . ' -f mp4 %s',
            $this->ffmpegPath,
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            escapeshellarg($sourcePath),
            $encoderFlags,
            escapeshellarg($outputPath),
        );

        $result = ProcessExecutor::exec($cmd, 120);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Init segment encoding failed: %s',
                $this->joinExecOutput($result),
            ));
        }

        return $outputPath;
    }

    /**
     * @param array{output?: string, error?: string} $result
     */
    private function joinExecOutput(array $result): string
    {
        return trim(($result['output'] ?? '') . ($result['error'] ?? ''));
    }
}
