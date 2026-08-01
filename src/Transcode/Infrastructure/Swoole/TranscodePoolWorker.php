<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use App\Transcode\Domain\Service\VideoProcessingRules;
use RuntimeException;

/**
 * Pool worker that executes FFmpeg commands in an isolated process.
 *
 * Runs without Symfony container — receives a serialized job payload,
 * executes FFmpeg, and returns the result.
 */
final class TranscodePoolWorker implements ProcessPoolWorkerInterface
{
    private const string FFMPEG_PATH = '/usr/bin/ffmpeg';

    public function supportedTypes(): array
    {
        return ['encode_segment', 'encode_init_segment', 'analyze_loudness', 'extract_subtitles'];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return match ($job['type'] ?? '') {
            'encode_segment' => $this->encodeSegment($job),
            'encode_init_segment' => $this->encodeInitSegment($job),
            'analyze_loudness' => $this->analyzeLoudness($job),
            'extract_subtitles' => $this->extractSubtitles($job),
            default => throw new RuntimeException(sprintf('Unknown job type: %s', $job['type'] ?? 'null')),
        };
    }

    private function encodeSegment(array $job): string
    {
        $flagsError = $this->validateFlags($job);
        if ($flagsError !== null) {
            return $flagsError;
        }

        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Use the HLS muxer with fmp4 segments. It produces standard CMAF
        // fragments (styp + sidx + moof + mdat) without any manual box
        // stripping, avoiding the intermittent "no moof" failures of the
        // standalone -f mp4 approach.
        $result = $this->encodeFmp4VideoSegment(
            $job['source_path'],
            (float) $job['start_time'],
            (float) $job['duration'],
            $job,
            $outputPath,
            false,
        );

        $metrics = $this->parseFfmpegMetrics($result['stderr'] ?? '');

        return json_encode(['success' => true, 'output_path' => $outputPath, 'metrics' => $metrics]);
    }

    private function encodeInitSegment(array $job): string
    {
        $flagsError = $this->validateFlags($job);
        if ($flagsError !== null) {
            return $flagsError;
        }

        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // The init segment must contain only ftyp+moov. The HLS muxer writes a
        // clean init.mp4 when asked for a sub-second encode, so keep only that.
        $this->encodeFmp4VideoSegment(
            $job['source_path'],
            0.0,
            0.1,
            $job,
            $outputPath,
            true,
        );

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
     * Validate that decoder/hwaccel flags only contain safe FFmpeg option
     * characters. This prevents shell command injection via payload fields.
     */
    private function validateFlags(array $job): ?string
    {
        $safePattern = '/^[a-zA-Z0-9_\-\.\s=:,\/]*$/';

        foreach (['decoder_flags', 'hwaccel_flags'] as $field) {
            $value = $job[$field] ?? '';
            if ($value === '') {
                continue;
            }

            if (!is_string($value) || preg_match($safePattern, $value) !== 1) {
                return json_encode([
                    'error' => sprintf('Invalid %s: shell metacharacters are not allowed.', $field),
                ], JSON_THROW_ON_ERROR);
            }
        }

        return null;
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

    /**
     * Encode a video segment using ffmpeg's HLS muxer with fmp4 segments.
     *
     * Each invocation encodes exactly one CMAF fragment. The HLS muxer handles
     * moof/mdat generation and produces a standalone segment file that works
     * with EXT-X-MAP, without the fragile init-box stripping required by the
     * raw -f mp4 path.
     *
     * @return array{stderr: string, duration: float}
     */
    private function encodeFmp4VideoSegment(
        string $sourcePath,
        float $startTime,
        float $duration,
        array $job,
        string $segmentOutputPath,
        bool $keepInitOnly,
        ?string $initOutputPath = null,
    ): array {
        $encoderFlags = VideoProcessingRules::codecFlags($job['encoder_config'] ?? 'libx265');
        $hwAccelFlags = $job['hwaccel_flags'] ?? '';
        $decoderFlags = $job['decoder_flags'] ?? '';
        $vf = $this->filterArg('vf', $job['video_filters'] ?? '');

        $tmpDir = dirname($segmentOutputPath) . '/.tmp_' . uniqid('video', true);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $playlistPath = $tmpDir . '/video.m3u8';
        $segmentPattern = $tmpDir . '/seg_%d.m4s';

        $cmd = sprintf(
            '%s -y %s%s -ss %.6f -t %.6f -i %s'
            . ' %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' %s -an'
            . ' -f hls -hls_segment_type fmp4 -hls_time %d -hls_playlist_type vod'
            . ' -hls_segment_filename %s'
            . ' %s',
            self::FFMPEG_PATH,
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            $startTime,
            $duration,
            escapeshellarg($sourcePath),
            $encoderFlags,
            $job['video_bitrate'],
            $job['max_bitrate'],
            $job['buffer_size'],
            $vf,
            (int) ceil($duration),
            escapeshellarg($segmentPattern),
            escapeshellarg($playlistPath),
        );

        $result = $this->exec($cmd, 300);
        if ($result['code'] !== 0) {
            $this->cleanupDir($tmpDir);
            throw new RuntimeException(
                'Video segment encoding failed: ' . ($result['stderr'] ?: 'unknown error'),
            );
        }

        $initSrc = $tmpDir . '/init.mp4';
        $segmentSrc = $tmpDir . '/seg_0.m4s';

        if (!is_file($initSrc) || !is_file($segmentSrc)) {
            $this->cleanupDir($tmpDir);
            throw new RuntimeException('HLS muxer did not produce expected init/segment files');
        }

        if ($keepInitOnly) {
            $this->atomicWriteWithLock($initSrc, $segmentOutputPath);
        } else {
            $this->atomicWriteWithLock($segmentSrc, $segmentOutputPath);
            if ($initOutputPath !== null) {
                $this->atomicWriteWithLock($initSrc, $initOutputPath);
            }
        }

        $this->cleanupDir($tmpDir);

        return [
            'stderr' => $result['stderr'] ?? '',
            'duration' => $this->parseDurationFromStderr($result['stderr'] ?? '', $duration),
        ];
    }

    /**
     * Move a finished temp file to its final path under an exclusive file lock.
     *
     * If the final path already exists and is non-empty, the temp file is
     * discarded — another worker won the race. This prevents two workers from
     * corrupting the same segment with overlapping writes.
     */
    private function atomicWriteWithLock(string $tempPath, string $finalPath): void
    {
        $lockPath = $finalPath . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new RuntimeException(sprintf('Failed to open lock file: %s', $lockPath));
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new RuntimeException(sprintf('Failed to acquire lock for: %s', $finalPath));
        }

        try {
            if (is_file($finalPath) && filesize($finalPath) > 0) {
                // Another worker already produced this segment.
                @unlink($tempPath);

                return;
            }

            if (!rename($tempPath, $finalPath)) {
                throw new RuntimeException(sprintf('Failed to rename %s to %s', $tempPath, $finalPath));
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
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

    /**
     * Strip ftyp + moov init boxes from a standalone fragmented MP4 file.
     *
     * FFmpeg writes each individually-encoded segment as a complete fragmented
     * MP4 (ftyp, moov, moof, mdat). When the playlist uses EXT-X-MAP, the
     * segment files must contain only the movie fragment (moof + mdat). This
     * method rewrites the file starting at the first top-level moof atom.
     */
    private function stripInitBoxes(string $inputPath, string $outputPath): void
    {
        $data = file_get_contents($inputPath);
        if ($data === false) {
            throw new RuntimeException('Failed to read segment for init-box stripping');
        }

        $length = strlen($data);
        $offset = 0;
        $moofOffset = null;

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);

            if ($size === 0) {
                break;
            }

            if ($size === 1) {
                // Extended size (64-bit). Rare for top-level atoms here.
                if ($offset + 16 > $length) {
                    break;
                }
                $size = unpack('J', substr($data, $offset + 8, 8))[1];
            }

            if ($type === 'moof') {
                $moofOffset = $offset;
                break;
            }

            $offset += $size;
        }

        if ($moofOffset === null) {
            throw new RuntimeException('No moof atom found in encoded segment');
        }

        $fragment = substr($data, $moofOffset);
        $written = file_put_contents($outputPath, $fragment, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Failed to write stripped segment');
        }
    }
}
