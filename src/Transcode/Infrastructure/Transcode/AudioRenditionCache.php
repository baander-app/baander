<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Infrastructure\Swoole\Async;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use Generator;
use Psr\Log\LoggerInterface;
use Throwable;

/** Admits durable shared work and follows only its accepted execution generation. */
final class AudioRenditionCache implements AudioRenditionPortInterface
{
    private const int CHUNK_BYTES = 65_536;

    public function __construct(
        private readonly SegmentFileResolver $paths,
        private readonly AudioRenditionRuntimePort $runtime,
        private readonly LoggerInterface $logger,
        private readonly float $pollIntervalSeconds = 0.05,
        private readonly float $startTimeoutSeconds = 60.0,
    ) {}

    public function open(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate): AudioRendition
    {
        $bitrate = $format->supportedBitrate($bitrate);
        clearstatcache(true, $sourcePath);
        $modified = @filemtime($sourcePath);
        $size = @filesize($sourcePath);
        if ($modified === false || $size === false) {
            $this->fail('the source file cannot be read');
        }
        $signature = $format->sourceSignature($bitrate, $modified, $size);
        try {
            $input = new AudioRenditionInput($trackKey, $signature, $sourcePath, $format, $bitrate, $this->paths->resolveAudioRenditionPath($trackKey, $signature));
            $admitted = $this->runtime->admit($input);
        } catch (Throwable $error) {
            $this->fail($error->getMessage());
        }
        $deadline = microtime(true) + $this->startTimeoutSeconds;
        do {
            $current = $this->runtime->find($admitted->key) ?? $admitted;
            if ($current->state === 'failed') {
                $this->fail($current->error ?? 'the encoder failed');
            }
            if ($current->state === 'complete') {
                $this->validatePaths($current);
                if (!is_file((string) $current->finalPath)) {
                    $this->fail('the completed rendition is missing');
                }
                return AudioRendition::complete($format, (string) $current->finalPath);
            }
            if ($current->state === 'running') {
                if (!$current->ownershipLive) {
                    $this->fail('the audio encoder lost ownership');
                }
                $this->validatePaths($current);
                clearstatcache(true, (string) $current->partialPath);
                clearstatcache(true, (string) $current->finalPath);
                if ((int) @filesize((string) $current->partialPath) > 0 || (int) @filesize((string) $current->finalPath) > 0) {
                    return AudioRendition::progressive($format, $this->follow($current));
                }
            }
            if (microtime(true) >= $deadline) {
                $this->fail('the encode did not start in time');
            }
            Async::sleep($this->pollIntervalSeconds);
        } while (true);
    }

    /** @return Generator<int, string> */
    private function follow(AudioRenditionRuntimeSnapshot $pinned): Generator
    {
        $handle = null;
        try {
            while (true) {
                $current = $this->runtime->find($pinned->key);
                if ($current === null || $current->generation !== $pinned->generation
                    || $current->partialPath !== $pinned->partialPath || $current->finalPath !== $pinned->finalPath
                    || !in_array($current->state, ['running', 'complete'], true)
                    || ($current->state !== 'complete' && !$current->ownershipLive)
                ) {
                    $this->fail('the audio rendition generation stopped or lost ownership');
                }
                $this->validatePaths($current);
                if ($handle === null) {
                    // Rename may happen between either open; both names refer to this generation.
                    $handle = @fopen((string) $pinned->partialPath, 'rb') ?: @fopen((string) $pinned->finalPath, 'rb') ?: null;
                }
                if ($handle !== null) {
                    $chunk = fread($handle, self::CHUNK_BYTES);
                    if ($chunk === false) {
                        $this->fail('the audio rendition cannot be read');
                    }
                    if ($chunk !== '') {
                        yield $chunk;
                        continue;
                    }
                    fseek($handle, 0, SEEK_CUR);
                }
                if ($current->state === 'complete') {
                    if ($handle === null) {
                        $this->fail('the completed rendition is missing');
                    }
                    return;
                }
                Async::sleep($this->pollIntervalSeconds);
            }
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
        }
    }

    private function validatePaths(AudioRenditionRuntimeSnapshot $runtime): void
    {
        $final = $runtime->generationDirectory() . '/' . $runtime->input->sourceSignature;
        if ($runtime->finalPath !== $final || $runtime->partialPath !== $final . SegmentFileResolver::PARTIAL_SUFFIX) {
            $this->fail('the accepted rendition paths are invalid');
        }
        try {
            $this->paths->resolvePath($final);
            $this->paths->resolvePath($final . SegmentFileResolver::PARTIAL_SUFFIX);
        } catch (Throwable $error) {
            $this->fail($error->getMessage());
        }
    }

    private function fail(string $reason): never
    {
        $this->logger->error('Audio rendition failed: {reason}', ['reason' => $reason]);
        throw new AudioRenditionFailedException('Audio transcoding failed: ' . $reason);
    }
}
