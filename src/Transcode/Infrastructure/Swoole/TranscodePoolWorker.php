<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\AudioProfile;
use RuntimeException;

/**
 * Pool worker that executes FFmpeg commands in an isolated process.
 *
 * Runs without Symfony container — receives a serialized job payload,
 * executes FFmpeg, and returns the result.
 */
final class TranscodePoolWorker implements ProcessPoolWorkerInterface
{
    private const string FFMPEG_PATH = '/usr/local/bin/ffmpeg';

    public function supportedTypes(): array
    {
        return ['encode_segment', 'encode_init_segment', 'analyze_loudness', 'encode_audio_init_segment', 'encode_audio_segment', 'extract_subtitles'];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return match ($job['type'] ?? '') {
            'encode_segment' => $this->encodeSegment($job),
            'encode_init_segment' => $this->encodeInitSegment($job),
            'analyze_loudness' => $this->analyzeLoudness($job),
            'encode_audio_init_segment' => $this->encodeAudioInitSegment($job),
            'encode_audio_segment' => $this->encodeAudioSegment($job),
            'extract_subtitles' => $this->extractSubtitles($job),
            default => throw new RuntimeException(sprintf('Unknown job type: %s', $job['type'] ?? 'null')),
        };
    }

    private function encodeSegment(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $encoderFlags = VideoProcessingRules::codecFlags($job['encoder_config'] ?? 'libx265');
        $hwAccelFlags = $job['hwaccel_flags'] ?? '';
        $decoderFlags = $job['decoder_flags'] ?? '';

        $cmd = sprintf(
            '%s -y %s%s -ss %.6f -t %.6f -i %s'
            . ' %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' %s %s'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -f mp4 %s',
            self::FFMPEG_PATH,
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            $job['start_time'],
            $job['duration'],
            escapeshellarg($job['source_path']),
            $encoderFlags,
            $job['video_bitrate'],
            $job['max_bitrate'],
            $job['buffer_size'],
            $this->filterArg('vf', $job['video_filters'] ?? ''),
            $this->filterArg('af', $job['audio_filters'] ?? ''),
            escapeshellarg($outputPath),
        );

        $result = $this->exec($cmd, 300);

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Segment encoding failed: ' . ($result['stderr'] ?: $result['output'] ?: 'unknown error'),
            );
        }

        $metrics = $this->parseFfmpegMetrics($result['stderr']);

        return json_encode(['success' => true, 'output_path' => $outputPath, 'metrics' => $metrics]);
    }

    private function encodeInitSegment(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $encoderFlags = VideoProcessingRules::initSegmentFlags($job['encoder_config'] ?? 'libx265');
        $hwAccelFlags = $job['hwaccel_flags'] ?? '';
        $decoderFlags = $job['decoder_flags'] ?? '';

        $cmd = sprintf(
            '%s -y %s%s -i %s %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -an -f mp4 %s',
            self::FFMPEG_PATH,
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            escapeshellarg($job['source_path']),
            $encoderFlags,
            $job['video_bitrate'],
            $job['max_bitrate'],
            $job['buffer_size'],
            escapeshellarg($outputPath),
        );

        $result = $this->exec($cmd, 120);

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Init segment encoding failed: ' . ($result['stderr'] ?: $result['output'] ?: 'unknown error'),
            );
        }

        return json_encode(['success' => true, 'output_path' => $outputPath]);
    }

    private function analyzeLoudness(array $job): string
    {
        $cmd = sprintf(
            '%s -i %s -af %s -f null -',
            self::FFMPEG_PATH,
            escapeshellarg($job['source_path']),
            escapeshellarg($job['loudness_filter']),
        );

        $result = $this->exec($cmd, 600);

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Loudness analysis failed: ' . ($result['stderr'] ?: $result['output'] ?: 'unknown error'),
            );
        }

        $loudness = $this->parseLoudnormOutput($result['stderr'] ?: $result['output']);

        return json_encode(['success' => true, 'loudness' => $loudness]);
    }

    private function filterArg(string $flag, string $filter): string
    {
        if ($filter === '') {
            return '';
        }

        return sprintf('-%s %s', $flag, escapeshellarg($filter));
    }

    /**
     * @return array{code: int, output: string, stderr: string}
     */
    private function exec(string $cmd, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if ($process === false) {
            return ['code' => -1, 'output' => 'Failed to start process', 'stderr' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            if (($status['running'] ?? false) === false) {
                // Process exited — drain remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if ((time() - $startTime) >= $timeout) {
                proc_terminate($process, 9); // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['code' => -1, 'output' => sprintf('Process timed out after %d seconds', $timeout), 'stderr' => ''];
            }

            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            $changed = stream_select($r, $w, $e, 1);

            if ($changed > 0) {
                foreach ($r as $stream) {
                    $data = fread($stream, 65536);
                    if ($data !== false && $data !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $data;
                        } else {
                            $stderr .= $data;
                        }
                    }
                }
            }

            Async::sleep(0.1);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => $status['exitcode'] ?? -1, 'output' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Parse encoding FPS and speed metrics from FFmpeg stderr output.
     *
     * FFmpeg outputs progress lines like:
     *   frame=  360 fps= 73.5 q=28.0 size=    1024kB time=00:00:12.00 bitrate= 699.1kbits/s speed=2.45x
     *
     * @return array{encodingFps?: float, encodingSpeed?: float}
     */
    private function parseFfmpegMetrics(string $stderr): array
    {
        $metrics = [];

        // Grab the last progress line (most representative)
        if (preg_match_all('/fps=\s*([\d.]+).*speed=\s*([\d.]+)x/', $stderr, $matches, PREG_SET_ORDER)) {
            $last = end($matches);
            $metrics['encodingFps'] = (float) $last[1];
            $metrics['encodingSpeed'] = (float) $last[2];
        }

        return $metrics;
    }

    private function encodeAudioInitSegment(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $profile = AudioProfile::fromString($job['audio_profile_name'] ?? 'streaming_stereo');
        $codecOptions = AudioProcessingRules::codecOptions($profile);

        $cmd = sprintf(
            '%s -y -i %s'
            . ' -vn -t 0.001'
            . ' %s -ar %d -ac %d'
            . ' -movflags +frag_keyframe+empty_moov+default_base_moof'
            . ' -f mp4 %s',
            self::FFMPEG_PATH,
            escapeshellarg($job['source_path']),
            $codecOptions,
            $job['sample_rate'] ?? 48000,
            $job['channels'] ?? 2,
            escapeshellarg($outputPath),
        );

        $result = $this->exec($cmd, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Audio init segment encoding failed: ' . ($result['stderr'] ?: 'unknown error'),
            );
        }

        return json_encode(['success' => true, 'output_path' => $outputPath]);
    }

    private function encodeAudioSegment(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $profile = AudioProfile::fromString($job['audio_profile_name'] ?? 'streaming_stereo');
        $codecOptions = AudioProcessingRules::codecOptions($profile);

        $cmd = sprintf(
            '%s -y -ss %.6f -t %.6f -i %s'
            . ' -vn'
            . ' %s -ar %d -ac %d'
            . ' %s'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -f mp4 %s',
            self::FFMPEG_PATH,
            (float) $job['start_time'],
            (float) $job['duration'],
            escapeshellarg($job['source_path']),
            $codecOptions,
            (int) ($job['sample_rate'] ?? 48000),
            (int) ($job['channels'] ?? 2),
            $this->filterArg('af', $job['audio_filters'] ?? ''),
            escapeshellarg($outputPath),
        );

        $result = $this->exec($cmd, 300);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Audio segment encoding failed: ' . ($result['stderr'] ?: 'unknown error'),
            );
        }

        $metrics = $this->parseFfmpegMetrics($result['stderr']);
        $duration = $this->parseDurationFromStderr($result['stderr'], (float) ($job['duration'] ?? 6.0));

        return json_encode([
            'success' => true,
            'output_path' => $outputPath,
            'metrics' => $metrics,
            'duration' => $duration,
        ]);
    }

    private function extractSubtitles(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $language = $job['language'] ?? 'und';
        // Validate language is alphanumeric (BCP-47 tags) before FFmpeg interpolation
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $language)) {
            throw new RuntimeException(sprintf('Invalid language tag for subtitle extraction: "%s"', $language));
        }
        $mapArg = sprintf('0:s:m:language:%s', $language);

        $cmd = sprintf(
            '%s -y -i %s'
            . ' -map %s'
            . ' -vn -an'
            . ' -f webvtt %s',
            self::FFMPEG_PATH,
            escapeshellarg($job['source_path']),
            $mapArg,
            escapeshellarg($outputPath),
        );

        $result = $this->exec($cmd, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Subtitle extraction failed: ' . ($result['stderr'] ?: 'unknown error'),
            );
        }

        return json_encode(['success' => true, 'output_path' => $outputPath]);
    }

    /**
     * Parse actual segment duration from FFmpeg stderr output.
     *
     * FFmpeg outputs progress lines like:
     *   time=00:00:05.98
     *
     * Falls back to $fallbackDuration if no time= found.
     */
    private function parseDurationFromStderr(string $stderr, float $fallbackDuration): float
    {
        if (preg_match_all('/time=(\d+):(\d+):([\d.]+)/', $stderr, $matches, PREG_SET_ORDER)) {
            $last = end($matches);
            $hours = (float) $last[1];
            $minutes = (float) $last[2];
            $seconds = (float) $last[3];

            return $hours * 3600 + $minutes * 60 + $seconds;
        }

        return $fallbackDuration;
    }

    private function parseLoudnormOutput(string $output): array
    {
        if (!str_contains($output, '"input_i"')) {
            return [
                'input_i' => -23.0,
                'input_tp' => -1.0,
                'input_lra' => 11.0,
                'input_thresh' => -33.0,
                'target_offset' => 0.0,
            ];
        }

        if (preg_match('/\{[^}]+\}/', $output, $matches)) {
            $stats = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

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
