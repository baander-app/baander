<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Preview;

use App\Transcode\Application\Command\PrepareTimelinePreview;
use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Transcode\Application\Port\VideoTimelinePreviewPort;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class VideoTimelinePreview implements VideoTimelinePreviewPort
{
    public function __construct(
        private MessageBusInterface $bus,
        private string $cacheDirectory,
    ) {
    }

    public function describe(VideoSourceMetadata $source): array
    {
        try {
            return $this->describeAvailable($source);
        } catch (\Throwable) {
            return [
                'state' => 'unavailable',
                'url' => null,
                'interval' => 0.0,
                'width' => 160,
                'height' => 90,
                'columns' => 10,
                'count' => 0,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function describeAvailable(VideoSourceMetadata $source): array
    {
        $duration = $source->duration ?? 0.0;
        $origin = $source->sourceTimestampOrigin ?? $source->containerStartTime ?? 0.0;
        $count = is_finite($duration) && $duration > 0.0 ? min(100, max(1, (int) ceil($duration / 10))) : 0;
        $identity = self::cacheIdentity($source);
        if (is_file($this->path($identity, 'cancelled'))) {
            throw new \RuntimeException('Timeline preview was cancelled.');
        }
        $image = $this->path($identity, 'jpg');
        $state = $count === 0 || $source->videoStreamIndex === null ? 'unavailable' : (is_file($image) ? 'ready' : 'pending');
        if ($state === 'pending') {
            $this->directory();
            $this->prune();
            $marker = $this->path($identity, 'pending');
            $failed = $this->path($identity, 'failed');
            if (is_file($failed) && (int) filemtime($failed) >= time() - 300) {
                $state = 'failed';
            } elseif (!is_file($marker) && count(glob($this->cacheDirectory . '/*.pending') ?: []) >= 256) {
                throw new \RuntimeException('Timeline preview queue is full.');
            } elseif (($claim = @fopen($marker, 'x')) !== false) {
                fclose($claim);
                try {
                    $command = new PrepareTimelinePreview(
                        $source->filePath,
                        $identity,
                        $duration,
                        $origin,
                        $source->hash,
                        $source->videoStreamIndex ?? 0,
                    );
                    $this->bus->dispatch($command, [new TransportNamesStamp(['async'])]);
                } catch (\Throwable) {
                    @unlink($marker);
                    $state = 'failed';
                }
            }
        }

        return [
            'state' => $state,
            'url' => $state === 'ready' ? '/api/videos/' . $source->publicId . '/timeline-preview' : null,
            'interval' => $count > 0 ? $duration / $count : 0.0,
            'width' => 160,
            'height' => 90,
            'columns' => 10,
            'count' => $count,
        ];
    }

    public function image(VideoSourceMetadata $source): ?string
    {
        $path = $this->path(self::cacheIdentity($source), 'jpg');

        return is_file($path) && !is_file($this->path(self::cacheIdentity($source), 'cancelled')) ? $path : null;
    }

    public function prepare(
        string $path,
        string $identity,
        float $duration,
        float $origin,
        string $sourceHash,
        int $videoStreamIndex,
    ): void {
        if (
            !is_finite($duration) || $duration <= 0.0 || !is_finite($origin)
            || $videoStreamIndex < 0
            || !hash_equals(self::identity($path, $duration, $origin, $sourceHash, $videoStreamIndex), $identity)
        ) {
            return;
        }
        $this->directory();
        $lock = fopen($this->path($identity, 'lock'), 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock timeline preview.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }
        touch($this->path($identity, 'lock'));
        $temporary = $this->path($identity, bin2hex(random_bytes(8)) . '.part');
        try {
            if (is_file($this->path($identity, 'cancelled')) || is_file($this->path($identity, 'jpg'))) {
                return;
            }
            $count = min(100, max(1, (int) ceil($duration / 10)));
            $interval = $duration / $count;
            $filter = sprintf(
                'setpts=PTS-%.9F/TB,fps=1/%.9F:start_time=0,'
                    . 'scale=160:90:force_original_aspect_ratio=decrease,'
                    . 'pad=160:90:(ow-iw)/2:(oh-ih)/2,tile=10x%d:nb_frames=%d',
                $origin,
                $interval,
                (int) ceil($count / 10),
                $count,
            );
            $arguments = [
                '/usr/bin/ffmpeg', '-nostdin', '-v', 'fatal', '-y', '-threads', '2',
                '-copyts', '-i', $path, '-map', '0:' . $videoStreamIndex,
                '-an', '-sn', '-vf', $filter, '-frames:v', '1', '-q:v', '5',
                '-fs', '4000000', '-f', 'image2', '-c:v', 'mjpeg', $temporary,
            ];
            $cancelled = $this->path($identity, 'cancelled');
            $sourceUnchanged = static fn(): bool => !is_file($cancelled) && hash_equals(
                self::identity($path, $duration, $origin, $sourceHash, $videoStreamIndex),
                $identity,
            );
            $result = ProcessExecutor::exec($arguments, 120, $sourceUnchanged);
            clearstatcache(true, $temporary);
            $size = is_file($temporary) ? filesize($temporary) : false;
            if (
                $result['code'] !== 0 || $size === false || $size < 1 || $size > 4_000_000
                || !$sourceUnchanged()
            ) {
                throw new \RuntimeException('Timeline preview generation failed.');
            }
            if (!rename($temporary, $this->path($identity, 'jpg'))) {
                throw new \RuntimeException('Cannot publish timeline preview.');
            }
            @unlink($this->path($identity, 'failed'));
            $this->prune();
        } catch (\Throwable $error) {
            touch($this->path($identity, 'failed'));
            throw $error;
        } finally {
            @unlink($temporary);
            @unlink($this->path($identity, 'pending'));
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function cacheIdentity(VideoSourceMetadata $source): string
    {
        return self::identity(
            $source->filePath,
            $source->duration ?? 0.0,
            $source->sourceTimestampOrigin ?? $source->containerStartTime ?? 0.0,
            $source->hash,
            $source->videoStreamIndex ?? 0,
        );
    }

    public static function removeCachedSource(string $cacheDirectory, string $identity): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1 || !is_dir($cacheDirectory)) {
            return;
        }
        touch($cacheDirectory . '/' . $identity . '.cancelled');
        $lock = fopen($cacheDirectory . '/' . $identity . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock timeline preview cleanup.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire timeline preview cleanup lock.');
            }
            foreach (glob($cacheDirectory . '/' . $identity . '.*') ?: [] as $file) {
                if (!str_ends_with($file, '.lock') && !str_ends_with($file, '.cancelled')) {
                    @unlink($file);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function identity(
        string $path,
        float $duration,
        float $origin,
        string $sourceHash,
        int $videoStreamIndex,
    ): string {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return hash(
            'sha256',
            json_encode(
                [
                    $path,
                    $stat === false ? null : [
                        $stat['ino'],
                        $stat['size'],
                        $stat['mtime'],
                        $stat['ctime'],
                    ],
                    $duration,
                    $origin,
                    $sourceHash,
                    $videoStreamIndex,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function directory(): void
    {
        if (
            !is_dir($this->cacheDirectory)
            && !@mkdir($this->cacheDirectory, 0700, true)
            && !is_dir($this->cacheDirectory)
        ) {
            throw new \RuntimeException('Cannot create timeline preview cache.');
        }
    }

    private function path(string $identity, string $extension): string
    {
        return $this->cacheDirectory . '/' . $identity . '.' . $extension;
    }

    private function prune(): void
    {
        $images = glob($this->cacheDirectory . '/*.jpg') ?: [];
        usort($images, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($images as $index => $image) {
            if ($index >= 64 || (int) filemtime($image) < time() - 86400) {
                @unlink($image);
            }
        }
        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            $lifetime = str_ends_with($file, '.pending') ? 600 : 86400;
            if (!str_ends_with($file, '.jpg') && (int) filemtime($file) < time() - $lifetime) {
                @unlink($file);
            }
        }
    }
}
