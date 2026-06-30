<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\HLS\FMP4SegmentWriter;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\System;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FFmpegAdapter implements FFmpegPortInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly FFprobeAdapter $ffprobeAdapter,
        private readonly FMP4SegmentWriter $fmp4Writer,
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $ffmpegPath = '/usr/local/bin/ffmpeg',
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

    public function probeVideo(string $sourcePath): VideoProbeResult
    {
        return $this->ffprobeAdapter->probeVideo($sourcePath);
    }

    public function encodeInitSegment(
        string $sourcePath,
        QualityTier $qualityTier,
        string $outputPath,
    ): string {
        return $this->fmp4Writer->encodeInitSegment($sourcePath, $qualityTier, $outputPath);
    }

    public function encodeSegment(
        string $sourcePath,
        float $startTime,
        float $duration,
        QualityTier $qualityTier,
        array $audioProfile,
        string $videoFilters,
        string $audioFilters,
        string $outputPath,
    ): void {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $codecOptions = AudioProcessingRules::codecOptions(AudioProfile::fromString($audioProfile['name'] ?? 'streaming_stereo'));

        $vf = $videoFilters !== '' ? sprintf('-vf %s', escapeshellarg($videoFilters)) : '';
        $af = $audioFilters !== '' ? sprintf('-af %s', escapeshellarg($audioFilters)) : '';

        $encoderFlags = VideoProcessingRules::codecFlags($this->encoderProfile->encoder);

        // Resolve hardware decoder for the source codec if available
        $sourceCodec = '';
        try {
            $probe = $this->ffprobeAdapter->probeVideo($sourcePath);
            $sourceCodec = $probe->videoCodec;
        } catch (\Throwable) {
            // ffprobe may fail for some sources; continue without hardware decode
        }
        $resolvedProfile = $this->encoderProfile->withDecoderForSource($sourceCodec);
        $hwAccelFlags = $resolvedProfile->hwaccelInputFlags();
        $decoderFlags = $resolvedProfile->decoderFlags();

        $cmd = sprintf(
            '%s -y %s%s -ss %.6f -t %.6f -i %s'
            . ' %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' %s'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' %s'
            . ' -f mp4 %s',
            $this->ffmpegPath,
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            $startTime,
            $duration,
            escapeshellarg($sourcePath),
            $encoderFlags,
            $qualityTier->videoBitrate,
            $qualityTier->maxBitrate,
            $qualityTier->bufferSize,
            $vf,
            $af . ' ' . $codecOptions,
            escapeshellarg($outputPath),
        );

        $this->logger->debug('Encoding segment', [
            'start' => $startTime,
            'duration' => $duration,
            'output' => $outputPath,
        ]);

        $result = System::exec($cmd, 300);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Segment encoding failed: %s',
                $result['output'] ?? 'unknown error',
            ));
        }
    }

    public function analyzeAudioLoudness(string $sourcePath, LoudnessStandard $standard): array
    {
        $filter = AudioProcessingRules::loudnessFilter($standard);

        $cmd = sprintf(
            '%s -i %s -af %s -f null -',
            $this->ffmpegPath,
            escapeshellarg($sourcePath),
            escapeshellarg($filter),
        );

        $result = System::exec($cmd, 600);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Loudness analysis failed: %s',
                $result['output'] ?? 'unknown error',
            ));
        }

        return $this->parseLoudnormOutput($result['output']);
    }

    public function encodeAudioInitSegment(
        string $sourcePath,
        array $audioProfile,
        string $outputPath,
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $profile = AudioProfile::fromString($audioProfile['name'] ?? 'streaming_stereo');
        $codecOptions = AudioProcessingRules::codecOptions($profile);

        $cmd = sprintf(
            '%s -y -i %s'
            . ' -vn -t 0.001'
            . ' %s -ar %d -ac %d'
            . ' -movflags +frag_keyframe+empty_moov+default_base_moof'
            . ' -f mp4 %s',
            $this->ffmpegPath,
            escapeshellarg($sourcePath),
            $codecOptions,
            $audioProfile['sampleRate'] ?? 48000,
            $audioProfile['channelCount'] ?? 2,
            escapeshellarg($outputPath),
        );

        $result = System::exec($cmd, 120);
        if (($result['code'] ?? -1) !== 0) {
            throw new RuntimeException(sprintf(
                'Audio init segment encoding failed: %s',
                $result['output'] ?? 'unknown error',
            ));
        }

        return $outputPath;
    }

    public function encodeAudioSegment(
        string $sourcePath,
        float $startTime,
        float $duration,
        array $audioProfile,
        string $audioFilters,
        string $outputPath,
    ): void {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $afArg = $audioFilters !== '' ? '-af ' . escapeshellarg($audioFilters) : '';
        $profile = AudioProfile::fromString($audioProfile['name'] ?? 'streaming_stereo');
        $codecOptions = AudioProcessingRules::codecOptions($profile);

        $cmd = sprintf(
            '%s -y -ss %.6f -t %.6f -i %s'
            . ' -vn'
            . ' %s -ar %d -ac %d'
            . ' %s'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -f mp4 %s',
            $this->ffmpegPath,
            $startTime,
            $duration,
            escapeshellarg($sourcePath),
            $codecOptions,
            $audioProfile['sampleRate'] ?? 48000,
            $audioProfile['channelCount'] ?? 2,
            $afArg,
            escapeshellarg($outputPath),
        );

        $result = System::exec($cmd, 300);
        if (($result['code'] ?? -1) !== 0) {
            throw new RuntimeException(sprintf(
                'Audio segment encoding failed: %s',
                $result['output'] ?? 'unknown error',
            ));
        }
    }

    public function extractSubtitleTrack(
        string $sourcePath,
        string $language,
        string $outputPath,
    ): void {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $cmd = sprintf(
            '%s -y -i %s -map 0:s:m:language:%s -vn -an -f webvtt %s',
            $this->ffmpegPath,
            escapeshellarg($sourcePath),
            escapeshellarg($language),
            escapeshellarg($outputPath),
        );

        $result = System::exec($cmd, 120);
        if (($result['code'] ?? -1) !== 0) {
            throw new RuntimeException(sprintf(
                'Subtitle extraction failed for language "%s": %s',
                $language,
                $result['output'] ?? 'unknown error',
            ));
        }
    }

    /**
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}
     */
    private function parseLoudnormOutput(string $output): array
    {
        // FFmpeg loudnorm prints JSON stats to stderr
        if (!str_contains($output, '"input_i"')) {
            return [
                'input_i' => -23.0,
                'input_tp' => -1.0,
                'input_lra' => 11.0,
                'input_thresh' => -33.0,
                'target_offset' => 0.0,
            ];
        }

        // Extract the JSON block from the output
        if (preg_match('/\{[^}]+\}/', $output, $matches)) {
            $stats = $this->jsonEncoder->decode($matches[0], 'json');

            return [
                'input_i' => (float) ($stats['input_i'] ?? -23.0),
                'input_tp' => (float) ($stats['input_tp'] ?? -1.0),
                'input_lra' => (float) ($stats['input_lra'] ?? 11.0),
                'input_thresh' => (float) ($stats['input_thresh'] ?? -33.0),
                'target_offset' => (float) ($stats['target_offset'] ?? 0.0),
            ];
        }

        return [
            'input_i' => -23.0,
            'input_tp' => -1.0,
            'input_lra' => 11.0,
            'input_thresh' => -33.0,
            'target_offset' => 0.0,
        ];
    }
}
