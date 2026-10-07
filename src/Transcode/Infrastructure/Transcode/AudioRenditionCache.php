<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Swoole\AudioRenditionPoolWorker;
use Generator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * File-backed audio rendition cache under the transcode cache root.
 *
 * Renditions live in `audio-renditions/<track>/`, named by format, bitrate and
 * a fingerprint of the source file, so a replaced source is encoded again.
 * Encodes run in the CPU process pool ({@see AudioRenditionPoolWorker}), never
 * on the request's event loop. Outside the Swoole server (CLI, tests) the pool
 * is not running and the encode runs in this process before streaming.
 *
 * Coordination is through the filesystem, so it holds across HTTP workers: the
 * encoder's exclusive lock marks a running encode, the `.part` file is what
 * listeners read while it grows, and the final file appears (by rename, under
 * the lock) only when the rendition is complete.
 */
final class AudioRenditionCache implements AudioRenditionPortInterface
{
    private const int CHUNK_BYTES = 65_536;

    /** How long a finished pool job may take to publish its result. */
    private const float RESULT_GRACE_SECONDS = 2.0;

    /** @var array<string, array{data: string, status: string}> */
    private array $inlineResults = [];

    public function __construct(
        private readonly SegmentFileResolver $paths,
        private readonly CpuProcessPoolInterface $pool,
        private readonly AudioRenditionPoolWorker $inlineWorker,
        private readonly LoggerInterface $logger,
        private readonly string $ffmpegPath,
        private readonly float $pollIntervalSeconds = 0.05,
        private readonly float $startTimeoutSeconds = 60.0,
    ) {
    }

    public function open(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate): AudioRendition
    {
        $bitrate = $format->supportedBitrate($bitrate);
        $context = ['track' => $trackKey, 'format' => $format->value, 'bitrate' => $bitrate];
        $files = $this->files($trackKey, $sourcePath, $format, $bitrate, $context);

        clearstatcache(true, $files['output']);
        if (is_file($files['output'])) {
            return AudioRendition::complete($format, $files['output']);
        }

        $jobKey = $this->isEncoding($files['lock']) ? null : $this->startEncode($sourcePath, $format, $bitrate, $files, $context);
        $this->awaitFirstBytes($files, $jobKey, $context);

        return AudioRendition::progressive($format, $this->follow($files, $jobKey, $context));
    }

    /**
     * @param array<string, string|int> $context
     * @return array{output: string, partial: string, lock: string}
     */
    private function files(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate, array $context): array
    {
        clearstatcache(true, $sourcePath);
        $modified = @filemtime($sourcePath);
        $size = @filesize($sourcePath);
        if ($modified === false || $size === false) {
            $this->fail($context, 'the source file cannot be read');
        }

        $name = sprintf(
            '%s-%dk-%s',
            $format->value,
            intdiv($bitrate, 1000),
            hash('xxh64', sprintf('%d:%d', $modified, $size)),
        );
        try {
            $directory = $this->paths->resolveAudioRenditionDirectory($trackKey);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('the rendition directory cannot be created');
            }
            $output = $this->paths->resolveAudioRenditionPath($trackKey, $name . '.' . $format->extension());
            $lock = $this->paths->resolveAudioRenditionPath($trackKey, $name . '.lock');
        } catch (Throwable $error) {
            $this->fail($context, $error->getMessage());
        }

        return ['output' => $output, 'partial' => $output . SegmentFileResolver::PARTIAL_SUFFIX, 'lock' => $lock];
    }

    /**
     * Whether another encoder holds the rendition's lock right now.
     */
    private function isEncoding(string $lockPath): bool
    {
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }
        try {
            if (flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{output: string, partial: string, lock: string} $files
     * @param array<string, string|int> $context
     */
    private function startEncode(string $sourcePath, AudioRenditionFormat $format, int $bitrate, array $files, array $context): string
    {
        $key = CpuProcessPool::resultKey(AudioRenditionPoolWorker::JOB_TYPE, bin2hex(random_bytes(8)));
        $payload = json_encode([
            'type' => AudioRenditionPoolWorker::JOB_TYPE,
            'ffmpeg_path' => $this->ffmpegPath,
            'source_path' => $sourcePath,
            'output_path' => $files['output'],
            'lock_path' => $files['lock'],
            'format' => $format->value,
            'bitrate' => $bitrate,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if ($this->pool->isRunning()) {
            try {
                $this->pool->dispatch($payload, $key);
            } catch (Throwable $error) {
                $this->fail($context, 'the CPU process pool rejected the encode: ' . $error->getMessage());
            }

            return $key;
        }

        if (Async::inCoroutine()) {
            // Encoding here would block the server's event loop.
            $this->fail($context, 'the CPU process pool is not running');
        }

        try {
            $this->inlineResults[$key] = ['status' => 'ok', 'data' => $this->inlineWorker->handle($payload)];
        } catch (Throwable $error) {
            $this->inlineResults[$key] = ['status' => 'error', 'data' => $error->getMessage()];
        }

        return $key;
    }

    /**
     * Wait until the rendition has audio to send, so an encode that fails at
     * once is answered with an error instead of an empty stream.
     *
     * @param array{output: string, partial: string, lock: string} $files
     * @param array<string, string|int> $context
     */
    private function awaitFirstBytes(array $files, ?string &$jobKey, array $context): void
    {
        $deadline = microtime(true) + $this->startTimeoutSeconds;
        while (true) {
            clearstatcache(true, $files['output']);
            clearstatcache(true, $files['partial']);
            if (is_file($files['output']) || (int) @filesize($files['partial']) > 0) {
                return;
            }

            if ($jobKey !== null) {
                $result = $this->takeResult($jobKey);
                if ($result !== null) {
                    $jobKey = null;
                    if ($result['status'] !== 'ok') {
                        $this->fail($context, $result['data']);
                    }
                    clearstatcache(true, $files['output']);
                    if (!is_file($files['output'])) {
                        $this->fail($context, 'the encode finished without a rendition');
                    }

                    return;
                }
            } elseif (!$this->isEncoding($files['lock'])) {
                clearstatcache(true, $files['output']);
                if (!is_file($files['output'])) {
                    $this->fail($context, 'the running encode stopped without a rendition');
                }

                return;
            }

            if (microtime(true) >= $deadline) {
                $this->fail($context, 'the encode did not start in time');
            }
            Async::sleep($this->pollIntervalSeconds);
        }
    }

    /**
     * Stream the rendition from its first byte while the encoder writes it.
     *
     * @param array{output: string, partial: string, lock: string} $files
     * @param array<string, string|int> $context
     * @return Generator<int, string>
     */
    private function follow(array $files, ?string $jobKey, array $context): Generator
    {
        $handle = null;
        $failure = null;
        try {
            while (true) {
                if ($handle === null) {
                    clearstatcache(true, $files['output']);
                    $path = is_file($files['output']) ? $files['output'] : $files['partial'];
                    // The partial file can be renamed between the check and the open.
                    $handle = @fopen($path, 'rb') ?: null;
                }
                if ($handle !== null) {
                    $chunk = fread($handle, self::CHUNK_BYTES);
                    if ($chunk !== false && $chunk !== '') {
                        yield $chunk;
                        continue;
                    }
                    // Clear the end-of-file flag so bytes written later are read.
                    fseek($handle, 0, SEEK_CUR);
                }

                clearstatcache(true, $files['output']);
                if (is_file($files['output'])) {
                    if ($handle === null) {
                        continue;
                    }
                    // Complete: the encoder exited before the rename, so this is the end.
                    $rest = stream_get_contents($handle);
                    if ($rest !== false && $rest !== '') {
                        yield $rest;
                    }

                    return;
                }
                if (!$this->isEncoding($files['lock'])) {
                    clearstatcache(true, $files['output']);
                    if (is_file($files['output'])) {
                        continue;
                    }
                    $failure = 'the encode stopped before the rendition was complete';

                    return;
                }

                Async::sleep($this->pollIntervalSeconds);
            }
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
            $result = $jobKey === null ? null : $this->awaitResult($jobKey);
            if ($result !== null && $result['status'] !== 'ok') {
                $failure = $result['data'];
            }
            if ($failure !== null) {
                $this->logFailure($context, $failure);
            }
        }
    }

    /**
     * Collect this request's pool result so it does not linger in the pool's
     * result store. The encoder publishes it right after releasing its lock.
     *
     * @return array{data: string, status: string}|null
     */
    private function awaitResult(string $jobKey): ?array
    {
        $deadline = microtime(true) + self::RESULT_GRACE_SECONDS;
        while (($result = $this->takeResult($jobKey)) === null && microtime(true) < $deadline) {
            Async::sleep($this->pollIntervalSeconds);
        }

        return $result;
    }

    /** @return array{data: string, status: string}|null */
    private function takeResult(string $jobKey): ?array
    {
        if (isset($this->inlineResults[$jobKey])) {
            $result = $this->inlineResults[$jobKey];
            unset($this->inlineResults[$jobKey]);

            return $result;
        }

        return $this->pool->readResult($jobKey);
    }

    /** @param array<string, string|int> $context */
    private function fail(array $context, string $reason): never
    {
        $this->logFailure($context, $reason);

        throw new AudioRenditionFailedException('Audio transcoding failed: ' . $reason);
    }

    /** @param array<string, string|int> $context */
    private function logFailure(array $context, string $reason): void
    {
        $this->logger->error('Audio rendition of track {track} as {format} at {bitrate} bps failed: {reason}', $context + ['reason' => $reason]);
    }
}
