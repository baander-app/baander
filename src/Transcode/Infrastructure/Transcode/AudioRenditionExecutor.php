<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Coordinates one remote audio attempt and publishes while holding ownership. */
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
        } catch (NativeExecutionException $error) {
            if ($error->uncertain) {
                throw $error;
            }
            $this->runtime->fail($claim, $error->getMessage());
            $this->logger->error('Audio rendition native execution rejected: {reason}', ['reason' => $error->getMessage()]);
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
        $renewAt = 0.0;
        $stopped = false;
        $result = ProcessExecutor::execNative($arguments, self::TIMEOUT_SECONDS, function () use ($claim, $stopRequested, &$renewAt, &$stopped): bool {
            if ($stopRequested()) {
                $stopped = true;
                return false;
            }
            if (hrtime(true) >= $renewAt) {
                if (!$this->runtime->renew($claim)) {
                    throw new RuntimeException('Audio rendition worker lost ownership.');
                }
                $renewAt = hrtime(true) + 1_000_000_000;
            }
            return true;
        });
        if ($stopped) {
            return false;
        }
        clearstatcache(true, $partial);
        if ($result['code'] !== 0 || !is_file($partial) || filesize($partial) === 0) {
            throw new RuntimeException(sprintf('Audio encoder exited with code %d: %s', $result['code'], substr(trim($result['error']), -2000)));
        }
        return true;
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
