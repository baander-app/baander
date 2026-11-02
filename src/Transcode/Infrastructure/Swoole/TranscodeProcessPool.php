<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\HLS\WebVttTimeline;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Polls source probes and generation-scoped subtitles owned by execution. */
final class TranscodeProcessPool
{
    /** @var array<string, array{handle: NativeExecutionHandle, output: ?string, subtitleStartTime: float}> */
    private array $attempts = [];

    /** @var array<string, array{argv:list<string>, timeout:int, output:?string, subtitleStartTime:float}> */
    private array $pending = [];

    public function __construct(
        private readonly NativeExecutionClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly DeploymentProfile $deploymentProfile = new DeploymentProfile(),
        private readonly string $ffprobePath = '/usr/bin/ffprobe',
    ) {}

    public function isRunning(): bool
    {
        if (($this->client->handshake()['ready'] ?? null) !== true) {
            throw new NativeExecutionException('Media native execution peer is not ready.');
        }
        return true;
    }

    public function probeVideo(TranscodeJob $job, string $sourcePath, int $generation): string
    {
        $key = sprintf('probe_video:%s:%d', $job->getId()->toString(), $generation);
        $this->submit($key, FFprobeArguments::video($sourcePath, $this->ffprobePath), 60);
        return $key;
    }

    public function probeCopyKeyframes(TranscodeJob $job, string $sourcePath, int $streamIndex, int $generation): string
    {
        $key = sprintf('copy_keyframes:%s:%d', $job->getId()->toString(), $generation);
        $this->submit($key, [$this->ffprobePath, '-threads', '1', '-v', 'error', '-print_format', 'json',
            '-select_streams', (string) $streamIndex, '-skip_frame', 'nokey', '-show_frames',
            '-show_entries', 'frame=best_effort_timestamp_time,key_frame', $sourcePath], 3600);
        return $key;
    }

    public function extractSubtitles(TranscodeJob $job, string $sourcePath, int $streamIndex, string $outputPath, int $generation, float $startTime = 0.0, ?float $sourceTimestampOrigin = null): string
    {
        if ($streamIndex < 0 || $startTime < 0 || !is_finite($startTime)) {
            throw new \InvalidArgumentException('Invalid subtitle stream index or start time.');
        }
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create subtitle output directory.');
        }
        $key = sprintf('extract_subtitles:%s:%d:s%d', $job->getId()->toString(), $generation, $streamIndex);
        $argv = FFmpegArguments::subtitleExtraction($sourcePath, $streamIndex, $outputPath . '.part', $sourceTimestampOrigin);
        $this->submit($key, $argv, 120, $outputPath, $startTime);
        return $key;
    }

    /** @return array{data: string, status: string}|null */
    public function readResult(string $key): ?array
    {
        if (isset($this->pending[$key])) {
            return null;
        }
        $attempt = $this->attempts[$key] ?? throw new \LogicException('Unknown media helper result key.');
        $status = $this->client->inspect($attempt['handle']);
        if ($status['state'] === 'running') {
            return null;
        }
        if ($status['state'] !== 'exited') {
            throw new NativeExecutionException('Media helper outcome is unconfirmed.');
        }
        if (!$this->client->release($attempt['handle'])) {
            throw new NativeExecutionException('Media helper release is unconfirmed.');
        }
        unset($this->attempts[$key]);
        $this->startPending();
        if ($status['exitCode'] !== 0) {
            if ($attempt['output'] !== null) {
                @unlink($attempt['output'] . '.part');
            }
            return ['status' => 'error', 'data' => $status['stderr'] ?: ($status['error'] ?? 'Media helper failed.')];
        }
        if ($attempt['output'] !== null) {
            $temporary = $attempt['output'] . '.part';
            $stream = @fopen($temporary, 'rb');
            $header = $stream === false ? false : fread($stream, 6);
            if ($stream !== false) {
                fclose($stream);
            }
            if ($header !== 'WEBVTT') {
                @unlink($temporary);
                return ['status' => 'error', 'data' => 'Subtitle helper did not publish valid WebVTT.'];
            }
            $rebased = $temporary . '.rebased';
            try {
                (new WebVttTimeline())->rebase($temporary, $rebased, $attempt['subtitleStartTime']);
                if (!rename($rebased, $attempt['output'])) {
                    throw new \RuntimeException('Subtitle timeline could not be published.');
                }
            } catch (\RuntimeException $error) {
                return ['status' => 'error', 'data' => $error->getMessage()];
            } finally {
                @unlink($temporary);
                @unlink($rebased);
            }
        }
        return ['status' => 'ok', 'data' => $attempt['output'] === null ? $status['stdout'] : $this->jsonEncoder->encode([
            'success' => true, 'output_path' => $attempt['output'],
        ], 'json')];
    }

    /** Retire outstanding probes even when cancellation interrupts their polling. */
    public function shutdown(): void
    {
        $this->pending = [];
        $spawner = new NativeProcessSpawner($this->client);
        $failure = null;
        foreach ($this->attempts as $key => $attempt) {
            try {
                $spawner->signal($attempt['handle'], 'stop');
                $spawner->close($attempt['handle']);
                unset($this->attempts[$key]);
                if ($attempt['output'] !== null) {
                    @unlink($attempt['output'] . '.part');
                }
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param list<string> $argv */
    private function submit(string $key, array $argv, int $timeout, ?string $output = null, float $subtitleStartTime = 0.0): void
    {
        if (isset($this->attempts[$key]) || isset($this->pending[$key])) {
            return;
        }
        if (count($this->attempts) + count($this->pending) >= 8) {
            throw new NativeExecutionException('Media helper capacity is exhausted.', false);
        }
        $this->pending[$key] = ['argv' => $argv, 'timeout' => $timeout, 'output' => $output, 'subtitleStartTime' => $subtitleStartTime];
        $this->startPending();
    }

    private function startPending(): void
    {
        while ($this->pending !== [] && count($this->attempts) < $this->deploymentProfile->mediaHelperLimit()) {
            $key = array_key_first($this->pending);
            $pending = $this->pending[$key];
            $this->logger->debug('Submitting remote media helper', ['key' => $key]);
            $handle = $this->client->submit($pending['argv'], $pending['timeout']);
            $this->attempts[$key] = ['handle' => $handle, 'output' => $pending['output'], 'subtitleStartTime' => $pending['subtitleStartTime']];
            unset($this->pending[$key]);
        }
    }
}