<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\QoL\Domain\ValueObject\EncoderWork;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Async;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Infrastructure\Packaging\PublishedPresentation;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Exception\FFmpegProcessFailedException;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\CopySegmentArguments;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Manages long-lived FFmpeg processes for on-the-fly video transcoding.
 *
 * Each (job, tier) gets ONE continuous FFmpeg process that produces all video
 * segments via the DASH muxer with separate fMP4 tracks. The manager polls the output
 * directory for new segment files and signals their availability through the
 * SegmentAvailabilityInterface (Swoole Table).
 *
 * Lifecycle (hybrid seek/throttle):
 * - Throttle: SIGSTOP/SIGCONT (preserves amortized encoder init; 0.38s resume)
 * - Seek:     kill + restart-with-headroom (SIGSTOP cannot reposition the read head;
 *             cold-start ~0.6s is imperceptible)
 *
 * Native attempts run in execution; control polls their bounded status and
 * generation output without owning local child descriptors.
 */
final class TranscodeStreamManager
{
    private const int DEFAULT_MAX_CONCURRENT = 4;
    private const float POLL_INTERVAL_SECONDS = 0.1;

    /** @var array<string, array{identity: \stdClass, handle: mixed, output_dir: string, tier_name: string, availability_tier: string, marked: array<string, bool>, publication: ?PublishedPresentation, cpu_seconds: float}> */
    private array $streams = [];

    /** @var array<string, EncoderWork> encoder work of each job's stopped attempts */
    private array $retiredWork = [];

    public function __construct(
        private readonly SegmentAvailabilityInterface $availability,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly SegmentEncoder|Closure $segmentEncoder,
        private readonly LoggerInterface $logger,
        private readonly ProcessSpawnerInterface $spawner,
        private readonly int $maxConcurrentStreams = self::DEFAULT_MAX_CONCURRENT,
        private readonly PublishedPresentationReader $presentations = new PublishedPresentationReader(),
        private readonly CopySegmentArguments $copyArguments = new CopySegmentArguments(),
    ) {
    }

    /**
     * Start a long-lived video stream for a job+tier.
     *
     * Spawns FFmpeg via the injected ProcessSpawnerInterface and returns the
     * output directory path. The caller (TranscodeSessionSubscriber) should
     * poll availability via pollOnce() in a coroutine loop.
     *
     * @param string $videoFilters FFmpeg video filter chain, may be empty
     * @param ?int $startSegment Starting segment number (for seek restart)
     * @param float $sourceDuration Selected video end relative to the source clock origin; 0 when unknown
     * @return string Output directory path
     */
    public function startStream(
        TranscodeJob $job,
        string $sourcePath,
        QualityTier|string $tier,
        string $videoFilters,
        ?int $startSegment = null,
        ?string $outputDirectory = null,
        ?AudioProfile $audioProfile = null,
        string $audioFilters = '',
        ?int $generation = null,
        bool $hasAudio = true,
        float $sourceFrameRate = 0.0,
        ?float $sourceTimestampOrigin = null,
        int $audioStreamOrdinal = 0,
        ?PresentationSelection $presentationSelection = null,
        float $sourceBoundary = 0.0,
        float $sourceDuration = 0.0,
    ): string {
        $tierName = is_string($tier) ? $tier : $tier->name;
        $jobId = $job->getId();
        $jobKey = $jobId->toString();

        if (isset($this->streams[$jobKey])) {
            $this->logger->debug('Stream already running for job, returning existing', ['jobId' => $jobKey]);

            return $this->streams[$jobKey]['output_dir'];
        }

        $this->enforceConcurrency();

        $outputDir = $outputDirectory ?? $this->storage->resolveJobDirectory($job->getVideoId(), $tier);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = $presentationSelection?->mode === 'copy'
            ? $this->copyArguments->build($sourcePath, $presentationSelection, $outputDir, $sourceBoundary)
            : $this->encoder()->buildStreamArgs(
            $sourcePath,
            $tier,
            $videoFilters,
            $outputDir,
            $startSegment,
            audioStreamIndex: $audioStreamOrdinal,
            audioProfile: $audioProfile,
            audioFilters: $audioFilters,
            hasAudio: $hasAudio,
            sourceFrameRate: $sourceFrameRate,
            sourceTimestampOrigin: $sourceTimestampOrigin,
            sourceDuration: $sourceDuration,
        );

        $this->logger->info('Starting long-lived video stream', [
            'jobId' => $jobKey,
            'tier' => $tierName,
            'startSegment' => $startSegment,
        ]);

        $spawned = $this->spawner->spawn($command);

        $this->streams[$jobKey] = [
            'identity' => new \stdClass(),
            'handle' => $spawned,
            'output_dir' => $outputDir,
            'tier_name' => $tierName,
            'availability_tier' => $generation === null ? $tierName : $tierName . '/generation-' . $generation,
            'marked' => [],
            'publication' => null,
            'cpu_seconds' => 0.0,
        ];

        return $outputDir;
    }

    private function encoder(): SegmentEncoder
    {
        return $this->segmentEncoder instanceof Closure ? ($this->segmentEncoder)() : $this->segmentEncoder;
    }

    /**
     * Poll the output directory for new segment files and mark them ready.
     *
     * Returns true if the FFmpeg process is still running, false if it has exited.
     * The caller should stop polling when this returns false.
     *
     * This method is safe to call from a Swoole coroutine — it uses Async::sleep
     * for the poll interval, never blocking the event loop.
     */
    public function pollOnce(Uuid $jobId): bool
    {
        $jobKey = $jobId->toString();
        $entry = $this->streams[$jobKey] ?? null;

        if ($entry === null) {
            return false;
        }

        try {
            $this->scanForNewSegments($jobKey, $entry);
            $this->assertStreamIdentity($jobKey, $entry['identity']);

            // Preserve segment publication state across status RPCs.
            $this->streams[$jobKey] = $entry;

            $status = $this->spawner->status($entry['handle']);
            $entry['cpu_seconds'] = max($entry['cpu_seconds'], $status['cpuSeconds']);
            $running = $status['state'] === 'running';
            if (!in_array($status['state'], ['running', 'exited'], true)) {
                throw new RuntimeException('Native video attempt outcome is uncertain.');
            }
            $this->assertStreamIdentity($jobKey, $entry['identity']);

            // Status RPCs may yield while the producer publishes another index.
            $this->scanForNewSegments($jobKey, $entry);
            $this->assertStreamIdentity($jobKey, $entry['identity']);
            $this->streams[$jobKey] = $entry;

            if (!$running) {
                // Only a confirmed zero exit can acknowledge completion. Existing
                // cache fragments or an init hint cannot hide failure/unknown status.
                $exitCode = $status['exitCode'] ?? -1;
                $this->assertStreamIdentity($jobKey, $entry['identity']);
                $publication = $entry['publication'];
                $producedCount = count($publication?->track(MediaTrack::Video)->segments ?? []);
                $this->assertStreamIdentity($jobKey, $entry['identity']);
                if ($exitCode !== 0) {
                    throw new FFmpegProcessFailedException(
                        $exitCode === -1 ? 'FFmpeg exited without a confirmed exit status.' : sprintf('FFmpeg exited with code %d.', $exitCode),
                        $status['stderr'],
                        $exitCode,
                    );
                }
                if ($producedCount === 0 || $publication === null || !$publication->complete) {
                    throw FFmpegProcessFailedException::fromProcess($status['stderr'], $exitCode);
                }
            }

            return $running;
        } catch (TranscodeStreamReplaced) {
            return false;
        }
    }

    /**
     * Wait for the stream to complete, polling for availability.
     *
     * Blocks (via Async::sleep) until FFmpeg exits. Intended for use in
     * a Swoole coroutine.
     */
    public function waitForCompletion(Uuid $jobId): void
    {
        while ($this->pollOnce($jobId)) {
            Async::sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * Stop a stream — SIGKILL the FFmpeg process and clean up.
     */
    public function stopStream(Uuid $jobId): void
    {
        $jobKey = $jobId->toString();
        $entry = $this->streams[$jobKey] ?? null;

        if ($entry === null) {
            return;
        }

        // Detach before any callback/I/O can yield and start a replacement.
        // This stop operation owns only the captured process, not the job slot.
        unset($this->streams[$jobKey]);
        $cpuSeconds = $entry['cpu_seconds'];
        try {
            $this->logger->info('Stopping video stream', ['jobId' => $jobKey]);
            $this->spawner->signal($entry['handle'], 'stop');
        } finally {
            try {
                $cpuSeconds = max($cpuSeconds, $this->spawner->close($entry['handle']));
            } finally {
                $retired = $this->retiredWork[$jobKey] ?? new EncoderWork(0.0, 0.0);
                $this->retiredWork[$jobKey] = new EncoderWork($retired->cpuSeconds + $cpuSeconds,
                    $retired->outputSeconds + self::outputSeconds($entry['publication']));
            }
        }
    }

    /**
     * The encoder work a job has done so far, over its stopped attempts and the running one:
     * the encoder's CPU seconds as the execution daemon reports them, and the seconds of video
     * the attempts have published as segments.
     */
    public function encoderWork(Uuid $jobId): EncoderWork
    {
        $jobKey = $jobId->toString();
        $work = $this->retiredWork[$jobKey] ?? new EncoderWork(0.0, 0.0);
        $entry = $this->streams[$jobKey] ?? null;
        if ($entry === null) {
            return $work;
        }

        return new EncoderWork($work->cpuSeconds + $entry['cpu_seconds'],
            $work->outputSeconds + self::outputSeconds($entry['publication']));
    }

    /**
     * Returns the job's encoder work and forgets it; call after the job's stream has stopped.
     */
    public function takeEncoderWork(Uuid $jobId): EncoderWork
    {
        $work = $this->encoderWork($jobId);
        unset($this->retiredWork[$jobId->toString()]);

        return $work;
    }

    private static function outputSeconds(?PublishedPresentation $publication): float
    {
        $track = $publication?->track(MediaTrack::Video);
        if ($track === null || $track->timescale <= 0) {
            return 0.0;
        }
        $ticks = 0;
        foreach ($track->segments as $segment) {
            $ticks += $segment->duration;
        }

        return $ticks / $track->timescale;
    }

    /**
     * Pause a stream via SIGSTOP (buffer throttle).
     *
     * The FFmpeg process is frozen in memory — its encoder state and internal
     * buffers are preserved. Resume with resumeStream().
     */
    public function pauseStream(Uuid $jobId): void
    {
        $entry = $this->streams[$jobId->toString()] ?? null;
        if ($entry !== null) {
            $this->spawner->signal($entry['handle'], 'pause');
            $this->logger->debug('Stream paused (SIGSTOP)', ['jobId' => $jobId->toString()]);
        }
    }

    /**
     * Resume a paused stream via SIGCONT.
     */
    public function resumeStream(Uuid $jobId): void
    {
        $entry = $this->streams[$jobId->toString()] ?? null;
        if ($entry !== null) {
            $this->spawner->signal($entry['handle'], 'resume');
            $this->logger->debug('Stream resumed (SIGCONT)', ['jobId' => $jobId->toString()]);
        }
    }

    /**
     * Whether a stream is currently active for the given job.
     */
    public function isStreaming(Uuid $jobId): bool
    {
        return isset($this->streams[$jobId->toString()]);
    }

    /**
     * Get the active stream count (for monitoring / capacity checks).
     */
    public function getActiveStreamCount(): int
    {
        return count($this->streams);
    }

    /**
     * Stop all active streams (called on server shutdown).
     */
    public function stopAll(): void
    {
        foreach (array_keys($this->streams) as $jobKey) {
            $this->stopStream(Uuid::fromString($jobKey));
        }
    }

    /** Stop an abandoned poll without touching a replacement process. */
    private function assertStreamIdentity(string $jobKey, \stdClass $identity): void
    {
        if (($this->streams[$jobKey]['identity'] ?? null) !== $identity) {
            throw new TranscodeStreamReplaced();
        }
    }

    public function publication(Uuid $jobId): ?PublishedPresentation
    {
        return $this->streams[$jobId->toString()]['publication'] ?? null;
    }

    /**
     * @param array{identity: \stdClass, handle: mixed, output_dir: string, tier_name: string, availability_tier: string, marked: array<string,bool>, publication: ?PublishedPresentation, cpu_seconds: float} $entry
     */
    private function scanForNewSegments(string $jobKey, array &$entry): void
    {
        $this->assertStreamIdentity($jobKey, $entry['identity']);
        $entry['publication'] = $this->presentations->read($entry['output_dir']);
        $jobId = Uuid::fromString($jobKey);
        foreach ($entry['publication']->tracks ?? [] as $track) {
            foreach ($track->segments as $segment) {
                $this->assertStreamIdentity($jobKey, $entry['identity']);
                $key = $track->kind->value . ':' . $segment->ordinal;
                if (isset($entry['marked'][$key])) {
                    continue;
                }
                $this->availability->markReady($jobId, $entry['availability_tier'] . '/' . $track->kind->value,
                    $segment->ordinal, $entry['output_dir'] . '/' . $segment->file);
                $this->assertStreamIdentity($jobKey, $entry['identity']);
                $entry['marked'][$key] = true;
            }
        }
    }

    /**
     * Reject if at capacity. Prevents unbounded FFmpeg process spawning.
     */
    private function enforceConcurrency(): void
    {
        if (count($this->streams) >= $this->maxConcurrentStreams) {
            throw new RuntimeException(sprintf(
                'Maximum concurrent streams reached (%d/%d). Cannot start new stream.',
                count($this->streams),
                $this->maxConcurrentStreams,
            ));
        }
    }

}
