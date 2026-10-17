<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Runs in a fresh worker job process, with one connection and one bounded encoder child. */
final class AudioRenditionExecutor
{
    private const int TIMEOUT_SECONDS = 3600;

    public function __construct(
        private readonly AudioRenditionRuntimePort $runtime,
        private readonly LoggerInterface $logger,
        private readonly string $ffmpegPath,
    ) {}

    /** @param callable(): bool $stopRequested */
    public function run(AudioRenditionClaim $claim, callable $stopRequested): void
    {
        $input = $claim->runtime->input;
        $lock = null;
        $final = $claim->runtime->generationDirectory() . '/' . $input->sourceSignature;
        $partial = $final . SegmentFileResolver::PARTIAL_SUFFIX;
        try {
            clearstatcache(true, $input->sourcePath);
            $modified = @filemtime($input->sourcePath);
            $size = @filesize($input->sourcePath);
            if ($modified === false || $size === false || $input->format->sourceSignature($input->bitrate, $modified, $size) !== $input->sourceSignature) {
                throw new RuntimeException('the admitted audio source changed before encoding');
            }
            $directory = $claim->runtime->generationDirectory();
            if (!is_dir($input->outputDirectory) && !@mkdir($input->outputDirectory, 0755, true) && !is_dir($input->outputDirectory)) {
                throw new RuntimeException('Unable to create the audio rendition directory.');
            }
            $lock = @fopen($input->outputDirectory . '.lock', 'c');
            if ($lock === false) {
                throw new RuntimeException('Unable to open the audio rendition lock.');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                $this->runtime->defer($claim);
                return;
            }
            if ($stopRequested()) {
                $this->runtime->defer($claim);
                return;
            }
            if (!is_dir($directory) && !@mkdir($directory, 0755) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the audio generation directory.');
            }
            if (!$this->runtime->start($claim, $partial, $final)) {
                return;
            }
            if (!$this->encode($claim, $partial, $stopRequested)) {
                $this->runtime->defer($claim);
                return;
            }
            $this->runtime->withOwnership($claim, function () use ($claim, $partial, $final): void {
                if (!rename($partial, $final)) {
                    throw new RuntimeException('Unable to publish the audio rendition.');
                }
                if (!$this->runtime->complete($claim)) {
                    throw new RuntimeException('Audio rendition ownership expired before completion.');
                }
            });
        } catch (Throwable $error) {
            $this->runtime->fail($claim, $error->getMessage());
            $this->logger->error('Audio rendition worker failed: {reason}', ['reason' => $error->getMessage(), 'rendition' => $claim->runtime->key]);
        } finally {
            // A stale worker can clean only its own generation's incomplete file.
            if (is_file($partial)) {
                @unlink($partial);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param callable(): bool $stopRequested */
    private function encode(AudioRenditionClaim $claim, string $partial, callable $stopRequested): bool
    {
        $input = $claim->runtime->input;
        $arguments = [
            $this->ffmpegPath, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-threads', '1', '-filter_threads', '1', '-filter_complex_threads', '1', '-i', $input->sourcePath, '-map', '0:a:0',
            ...self::codecArguments($input->format), '-threads', '1', '-b:a', (string) $input->bitrate, $partial,
        ];
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Unable to start the audio encoder.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $renewAt = $started;
        $error = '';
        try {
            while (true) {
                // Drain both pipes every iteration and retain bounded diagnostics.
                fread($pipes[1], 65_536);
                $error = substr($error . fread($pipes[2], 65_536), -2000);
                $status = proc_get_status($process);
                if ($stopRequested()) {
                    return false;
                }
                if (microtime(true) >= $renewAt) {
                    if (!$this->runtime->renew($claim)) {
                        throw new RuntimeException('Audio rendition worker lost ownership.');
                    }
                    $renewAt = microtime(true) + 1.0;
                }
                if (!$status['running']) {
                    $error = substr($error . fread($pipes[2], 65_536), -2000);
                    clearstatcache(true, $partial);
                    if ($status['exitcode'] !== 0 || !is_file($partial) || filesize($partial) === 0) {
                        throw new RuntimeException(sprintf('Audio encoder exited with code %d: %s', $status['exitcode'], trim($error)));
                    }
                    return true;
                }
                if (microtime(true) - $started >= self::TIMEOUT_SECONDS) {
                    throw new RuntimeException('Audio encoder exceeded its execution deadline.');
                }
                usleep(50_000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $deadline = microtime(true) + .5;
                do {
                    usleep(10_000);
                    $status = proc_get_status($process);
                } while ($status['running'] && microtime(true) < $deadline);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /** @return list<string> */
    private static function codecArguments(AudioRenditionFormat $format): array
    {
        return match ($format) {
            AudioRenditionFormat::Opus => ['-c:a', 'libopus', '-f', 'ogg'],
            AudioRenditionFormat::Aac => ['-c:a', 'aac', '-f', 'adts'],
            AudioRenditionFormat::Mp3 => ['-c:a', 'libmp3lame', '-write_xing', '0', '-f', 'mp3'],
        };
    }
}
