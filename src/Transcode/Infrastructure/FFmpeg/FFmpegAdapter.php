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
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FFmpegAdapter implements FFmpegPortInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly FFprobeAdapter $ffprobeAdapter,
        private readonly FMP4SegmentWriter $fmp4Writer,
        private readonly JsonEncoder $jsonEncoder,
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

    /**
     * @param array<string, mixed> $audioProfile
     */
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

        $vf = $videoFilters !== '' ? ['-vf', $videoFilters] : [];
        $af = $audioFilters !== '' ? ['-af', $audioFilters] : [];

        $encoderFlags = VideoProcessingRules::codecFlags($this->encoderProfile->encoder);

        // Resolve hardware decoder for the source codec if available
        $sourceCodec = '';
        try {
            $probe = $this->ffprobeAdapter->probeVideo($sourcePath);
            $sourceCodec = $probe->videoCodec;
        } catch (\App\Shared\Infrastructure\Worker\NativeExecutionException $error) {
            throw $error;
        } catch (\Throwable) {
            // ffprobe may fail for some sources; continue without hardware decode
        }
        $resolvedProfile = $this->encoderProfile->withDecoderForSource($sourceCodec);
        $cmd = [
            $this->ffmpegPath, '-nostdin', '-y', ...FFmpegArguments::input($resolvedProfile),
            '-ss', sprintf('%.6f', $startTime), '-t', sprintf('%.6f', $duration), '-i', $sourcePath,
            ...FFmpegArguments::flags($encoderFlags),
            '-b:v', (string) $qualityTier->videoBitrate, '-maxrate', (string) $qualityTier->maxBitrate,
            '-bufsize', (string) $qualityTier->bufferSize, ...$vf,
            '-movflags', '+frag_keyframe+separate_moof+default_base_moof',
            ...$af, ...FFmpegArguments::flags($codecOptions), '-f', 'mp4', $outputPath,
        ];

        $this->logger->debug('Encoding segment', [
            'start' => $startTime,
            'duration' => $duration,
            'output' => $outputPath,
        ]);

        $result = ProcessExecutor::exec($cmd, 300);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Segment encoding failed: %s',
                $this->joinExecOutput($result),
            ));
        }
    }

    public function analyzeAudioLoudness(string $sourcePath, LoudnessStandard $standard): array
    {
        $filter = AudioProcessingRules::loudnessFilter($standard);

        $cmd = [$this->ffmpegPath, '-nostdin', '-i', $sourcePath, '-af', $filter, '-f', 'null', '-'];

        $result = ProcessExecutor::exec($cmd, 600);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Loudness analysis failed: %s',
                $this->joinExecOutput($result),
            ));
        }

        return $this->parseLoudnormOutput($result['error']);
    }

    /**
     * @param array<string, mixed> $audioProfile
     */
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

        $cmd = [
            $this->ffmpegPath, '-nostdin', '-y', '-i', $sourcePath, '-vn', '-t', '0.001',
            ...FFmpegArguments::flags($codecOptions), '-ar', (string) ($audioProfile['sampleRate'] ?? 48000),
            '-ac', (string) ($audioProfile['channelCount'] ?? 2),
            '-movflags', '+frag_keyframe+empty_moov+default_base_moof', '-f', 'mp4', $outputPath,
        ];

        $result = ProcessExecutor::exec($cmd, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Audio init segment encoding failed: %s',
                $this->joinExecOutput($result),
            ));
        }

        return $outputPath;
    }

    /**
     * @param array<string, mixed> $audioProfile
     */
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

        $afArg = $audioFilters !== '' ? ['-af', $audioFilters] : [];
        $profile = AudioProfile::fromString($audioProfile['name'] ?? 'streaming_stereo');
        $codecOptions = AudioProcessingRules::codecOptions($profile);

        $cmd = [
            $this->ffmpegPath, '-nostdin', '-y', '-ss', sprintf('%.6f', $startTime),
            '-t', sprintf('%.6f', $duration), '-i', $sourcePath, '-vn',
            ...FFmpegArguments::flags($codecOptions), '-ar', (string) ($audioProfile['sampleRate'] ?? 48000),
            '-ac', (string) ($audioProfile['channelCount'] ?? 2), ...$afArg,
            '-movflags', '+frag_keyframe+separate_moof+default_base_moof', '-f', 'mp4', $outputPath,
        ];

        $result = ProcessExecutor::exec($cmd, 300);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Audio segment encoding failed: %s',
                $this->joinExecOutput($result),
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

        $cmd = [$this->ffmpegPath, '-nostdin', '-y', '-i', $sourcePath, '-map', '0:s:m:language:' . $language, '-vn', '-an', '-f', 'webvtt', $outputPath];

        $result = ProcessExecutor::exec($cmd, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Subtitle extraction failed for language "%s": %s',
                $language,
                $this->joinExecOutput($result),
            ));
        }
    }

    /**
     * @param array{output?: string, error?: string} $result
     */
    private function joinExecOutput(array $result): string
    {
        return trim(($result['output'] ?? '') . ($result['error']));
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
