<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
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
        return ['probe_video', 'extract_subtitles'];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return match ($job['type'] ?? '') {
            'probe_video' => $this->probeVideo($job),
            'extract_subtitles' => $this->extractSubtitles($job),
            default => throw new RuntimeException(sprintf('Unknown job type: %s', $job['type'] ?? 'null')),
        };
    }

    /** @param array<string, mixed> $job */
    private function probeVideo(array $job): string
    {
        $result = $this->exec(['/usr/bin/ffprobe', '-threads', '2', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $job['source_path']], 60);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Video probe failed: ' . $result['stderr']);
        }
        return $result['output'];
    }

    /**
     * @param list<string> $cmd
     * @return array{code: int, output: string, stderr: string}
     */
    private function exec(array $cmd, int $timeout): array
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

            if (!$status['running']) {
                // Process exited — drain remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $exitCode = $status['exitcode'];
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
        proc_close($process);

        return ['code' => $exitCode, 'output' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function extractSubtitles(array $job): string
    {
        $outputPath = $job['output_path'];
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $language = $job['language'] ?? 'und';
        // Only BCP-47 language selectors belong in this FFmpeg mapping.
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $language)) {
            throw new RuntimeException(sprintf('Invalid language tag for subtitle extraction: "%s"', $language));
        }
        $mapArg = sprintf('0:s:m:language:%s', $language);

        $cmd = [self::FFMPEG_PATH, '-y', '-threads', '2', '-i', $job['source_path'], '-map', $mapArg,
            '-vn', '-an', '-f', 'webvtt', $outputPath];

        $result = $this->exec($cmd, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Subtitle extraction failed: ' . ($result['stderr'] ?: 'unknown error'),
            );
        }

        return json_encode(['success' => true, 'output_path' => $outputPath]);
    }
}
