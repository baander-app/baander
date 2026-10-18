<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use App\Transcode\Domain\Model\TranscodeJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Polls source probes and generation-scoped subtitles owned by execution. */
final class TranscodeProcessPool
{
    /** @var array<string, array{handle: NativeExecutionHandle, output: ?string}> */
    private array $attempts = [];

    public function __construct(
        private readonly NativeExecutionClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
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
        $this->submit($key, ['/usr/bin/ffprobe', '-threads', '2', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $sourcePath], 60);
        return $key;
    }

    public function extractSubtitles(TranscodeJob $job, string $sourcePath, string $language, string $outputPath, int $generation): string
    {
        if (preg_match('/^[a-zA-Z0-9-]+$/D', $language) !== 1) {
            throw new \InvalidArgumentException('Invalid language tag for subtitle extraction.');
        }
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create subtitle output directory.');
        }
        $key = sprintf('extract_subtitles:%s:%d:%s', $job->getId()->toString(), $generation, $language);
        $this->submit($key, ['/usr/bin/ffmpeg', '-y', '-threads', '2', '-i', $sourcePath, '-map', '0:s:m:language:' . $language,
            '-vn', '-an', '-f', 'webvtt', $outputPath], 120, $outputPath);
        return $key;
    }

    /** @return array{data: string, status: string}|null */
    public function readResult(string $key): ?array
    {
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
        if ($status['exitCode'] !== 0) {
            return ['status' => 'error', 'data' => $status['stderr'] ?: ($status['error'] ?? 'Media helper failed.')];
        }
        return ['status' => 'ok', 'data' => $attempt['output'] === null ? $status['stdout'] : $this->jsonEncoder->encode([
            'success' => true, 'output_path' => $attempt['output'],
        ], 'json')];
    }

    /** Retire outstanding probes even when cancellation interrupts their polling. */
    public function shutdown(): void
    {
        $spawner = new NativeProcessSpawner($this->client);
        $failure = null;
        foreach ($this->attempts as $key => $attempt) {
            try {
                $spawner->signal($attempt['handle'], 'stop');
                $spawner->close($attempt['handle']);
                unset($this->attempts[$key]);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param list<string> $argv */
    private function submit(string $key, array $argv, int $timeout, ?string $output = null): void
    {
        if (isset($this->attempts[$key])) {
            return;
        }
        if (count($this->attempts) >= 8) {
            throw new NativeExecutionException('Media helper capacity is exhausted.', false);
        }
        $this->logger->debug('Submitting remote media helper', ['key' => $key]);
        $handle = $this->client->submit($argv, $timeout);
        $this->attempts[$key] = ['handle' => $handle, 'output' => $output];
    }
}
