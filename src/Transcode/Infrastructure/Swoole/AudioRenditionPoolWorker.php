<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use RuntimeException;
use Throwable;

/**
 * Encodes one cached audio rendition of a track in an isolated process.
 *
 * The encode holds an exclusive lock on the rendition's lock file for its whole
 * run, so only one encode runs per rendition at a time, across every HTTP worker
 * and pool process. A job that finds the lock held returns at once with status
 * `running` instead of waiting. The kernel releases the lock if this process dies. FFmpeg
 * writes the `.part` file that listeners read while it grows; a successful encode
 * renames it to the final rendition while still holding the lock, and a failed
 * one removes it.
 *
 * Runs without the Symfony container: every input arrives in the payload.
 */
final class AudioRenditionPoolWorker implements ProcessPoolWorkerInterface
{
    public const string JOB_TYPE = 'encode_audio_rendition';

    /** Upper bound for one encode; long DJ mixes encode in a few minutes. */
    private const int TIMEOUT_SECONDS = 3600;

    public function supportedTypes(): array
    {
        return [self::JOB_TYPE];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($job)) {
            throw new RuntimeException('Audio rendition job payload must be an object.');
        }
        $ffmpeg = self::stringField($job, 'ffmpeg_path');
        $source = self::stringField($job, 'source_path');
        $output = self::stringField($job, 'output_path');
        $lockPath = self::stringField($job, 'lock_path');
        $format = AudioRenditionFormat::from(self::stringField($job, 'format'));
        $bitrate = $job['bitrate'] ?? null;
        if (!is_int($bitrate) || $bitrate <= 0) {
            throw new RuntimeException('Audio rendition job needs a positive bitrate.');
        }

        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the audio rendition directory.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the audio rendition lock.');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    // Another encode of this rendition is running and will publish
                    // it; waiting for it here would only hold a pool worker.
                    return json_encode(['status' => 'running', 'output_path' => $output], JSON_THROW_ON_ERROR);
                }
                throw new RuntimeException('Unable to lock the audio rendition.');
            }
            clearstatcache(true, $output);
            if (!is_file($output)) {
                $this->encode($ffmpeg, $source, $output, $format, $bitrate);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return json_encode(['status' => 'complete', 'output_path' => $output], JSON_THROW_ON_ERROR);
    }

    private function encode(string $ffmpeg, string $source, string $output, AudioRenditionFormat $format, int $bitrate): void
    {
        $partial = $output . SegmentFileResolver::PARTIAL_SUFFIX;
        // A partial file left by an encoder that died is never resumed.
        @unlink($partial);

        $arguments = [
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source,
            '-map', '0:a:0',
            ...self::codecArguments($format),
            '-b:a', (string) $bitrate,
            $partial,
        ];
        // `exec` replaces the shell, so a timeout kill reaches FFmpeg itself.
        $command = 'exec ' . implode(' ', array_map(escapeshellarg(...), $arguments));

        try {
            $result = ProcessExecutor::exec($command, self::TIMEOUT_SECONDS);
        } catch (Throwable $error) {
            @unlink($partial);
            throw new RuntimeException('Audio encoder did not finish: ' . $error->getMessage(), 0, $error);
        }

        clearstatcache(true, $partial);
        if ($result['code'] !== 0 || !is_file($partial) || filesize($partial) === 0) {
            @unlink($partial);
            throw new RuntimeException(sprintf(
                'Audio encoder exited with code %d: %s',
                $result['code'],
                substr(trim($result['error'] !== '' ? $result['error'] : $result['output']), -500),
            ));
        }

        if (!rename($partial, $output)) {
            @unlink($partial);
            throw new RuntimeException('Unable to complete the audio rendition.');
        }
    }

    /**
     * Encoder and muxer per format. Each muxer writes front to back without
     * seeking back, so bytes read while the file grows match the final file.
     *
     * @return list<string>
     */
    private static function codecArguments(AudioRenditionFormat $format): array
    {
        return match ($format) {
            AudioRenditionFormat::Opus => ['-c:a', 'libopus', '-f', 'ogg'],
            AudioRenditionFormat::Aac => ['-c:a', 'aac', '-f', 'adts'],
            AudioRenditionFormat::Mp3 => ['-c:a', 'libmp3lame', '-write_xing', '0', '-f', 'mp3'],
        };
    }

    /** @param array<mixed> $job */
    private static function stringField(array $job, string $name): string
    {
        $value = $job[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Audio rendition job is missing "%s".', $name));
        }

        return $value;
    }
}
