<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Async;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Exception\FFmpegProcessFailedException;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Manages long-lived FFmpeg processes for on-the-fly video transcoding.
 *
 * Each (job, tier) gets ONE continuous FFmpeg process that produces all video
 * segments via the HLS muxer with fMP4 fragments. The manager polls the output
 * directory for new segment files and signals their availability through the
 * SegmentAvailabilityInterface (Swoole Table).
 *
 * Lifecycle (KTD-3 — hybrid seek/throttle):
 * - Throttle: SIGSTOP/SIGCONT (preserves amortized encoder init; 0.38s resume)
 * - Seek:     kill + restart-with-headroom (SIGSTOP cannot reposition the read head;
 *             cold-start ~0.6s is imperceptible)
 *
 * Audio is muxed inline into each segment — the long process maps both
 * -map 0:v:0 -map 0:a:0, producing muxed fMP4 segments.
 *
 * Runs outside the CpuProcessPool — the one-shot handle()→string pool contract
 * is incompatible with a process that lives for minutes and emits N availability
 * events.
 */
final class TranscodeStreamManager
{
    private const int SIGSTOP = 19;
    private const int SIGCONT = 18;
    private const int SIGKILL = 9;
    private const int DEFAULT_MAX_CONCURRENT = 4;
    private const float POLL_INTERVAL_SECONDS = 0.1;

    /** @var array<string, array{resource: mixed, pipes: array<int, mixed>, pid: int, output_dir: string, tier_name: string, marked: array<int, bool>, init_marked: bool, stderr_buffer: string}> */
    private array $streams = [];

    public function __construct(
        private readonly SegmentAvailabilityInterface $availability,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly SegmentEncoder $segmentEncoder,
        private readonly LoggerInterface $logger,
        private readonly ProcessSpawnerInterface $spawner,
        private readonly int $maxConcurrentStreams = self::DEFAULT_MAX_CONCURRENT,
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
     * @return string Output directory path
     */
    public function startStream(
        TranscodeJob $job,
        string $sourcePath,
        QualityTier $tier,
        string $videoFilters,
        ?int $startSegment = null,
    ): string {
        $jobId = $job->getId();
        $jobKey = $jobId->toString();

        if (isset($this->streams[$jobKey])) {
            $this->logger->debug('Stream already running for job, returning existing', ['jobId' => $jobKey]);

            return $this->streams[$jobKey]['output_dir'];
        }

        $this->enforceConcurrency();

        $outputDir = $this->storage->resolveJobDirectory($job->getVideoId(), $tier);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = $this->segmentEncoder->buildStreamArgs(
            $sourcePath,
            $tier,
            $videoFilters,
            $outputDir,
            $startSegment,
        );

        $this->logger->info('Starting long-lived video stream', [
            'jobId' => $jobKey,
            'tier' => $tier->name,
            'startSegment' => $startSegment,
        ]);

        $spawned = $this->spawner->spawn($command);

        $this->streams[$jobKey] = [
            'resource' => $spawned['resource'],
            'pipes' => $spawned['pipes'],
            'pid' => $spawned['pid'],
            'output_dir' => $outputDir,
            'tier_name' => $tier->name,
            'marked' => [],
            'init_marked' => false,
            'stderr_buffer' => '',
        ];

        return $outputDir;
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

        $this->scanForNewSegments($jobKey, $entry);

        // Write back the modified entry (marked indices, init_marked, stderr_buffer)
        $this->streams[$jobKey] = $entry;

        $running = $this->spawner->isRunning($entry['resource']);

        if (!$running) {
            // Final scan to catch segments written after the last poll
            $this->scanForNewSegments($jobKey, $entry);
            $this->streams[$jobKey] = $entry;

            // The process exited. If it produced nothing, this is a failure —
            // not a normal completion. Surface the captured stderr so the
            // caller (encoding loop) can fail the job with a real reason
            // instead of hanging at in_progress forever.
            $producedCount = $this->countProducedSegments($entry['output_dir']);
            if (!$entry['init_marked'] && $producedCount === 0) {
                throw FFmpegProcessFailedException::fromProcess(
                    $entry['stderr_buffer'],
                    $this->spawner->exitCode($entry['resource']),
                );
            }
        }

        return $running;
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

        $this->logger->info('Stopping video stream', ['jobId' => $jobKey]);

        if ($this->spawner->isRunning($entry['resource'])) {
            $this->spawner->signal($entry['pid'], self::SIGKILL);
        }

        $this->spawner->close($entry['resource']);
        unset($this->streams[$jobKey]);
    }

    /**
     * Pause a stream via SIGSTOP (buffer-depth throttle).
     *
     * The FFmpeg process is frozen in memory — its encoder state and internal
     * buffers are preserved. Resume with resumeStream().
     */
    public function pauseStream(Uuid $jobId): void
    {
        $entry = $this->streams[$jobId->toString()] ?? null;
        if ($entry !== null && $this->spawner->isRunning($entry['resource'])) {
            $this->spawner->signal($entry['pid'], self::SIGSTOP);
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
            $this->spawner->signal($entry['pid'], self::SIGCONT);
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

    /**
     * @param array{resource: mixed, pipes: array<int, mixed>, pid: int, output_dir: string, tier_name: string, marked: array<int, bool>, init_marked: bool, stderr_buffer: string} $entry
     */
    private function scanForNewSegments(string $jobKey, array &$entry): void
    {
        // Drain FFmpeg stdout/stderr pipes to prevent pipe-buffer deadlock on
        // long encodes. FFmpeg writes progress/stats continuously; if the pipe
        // buffer (~64KB) fills and is never read, FFmpeg blocks on its next
        // write and the encode stalls.
        //
        // stderr is accumulated (not discarded) so that if the process exits
        // without producing output, FFmpegProcessFailedException can surface
        // the real reason. stdout is discarded (progress noise).
        foreach ($entry['pipes'] as $idx => $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }
            stream_set_blocking($pipe, false);
            while ($chunk = fread($pipe, 65536)) {
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                // pipes array is [1 => stdout, 2 => stderr] after stdin was
                // closed in the spawner. Index 2 is stderr.
                if ($idx === 2) {
                    $entry['stderr_buffer'] .= $chunk;
                    // Bound the buffer — FFmpeg progress can be verbose on long
                    // encodes, but only the tail carries the fatal error.
                    if (strlen($entry['stderr_buffer']) > 65536) {
                        $entry['stderr_buffer'] = substr($entry['stderr_buffer'], -65536);
                    }
                }
            }
        }

        $dir = $entry['output_dir'];
        $jobId = Uuid::fromString($jobKey);

        // Check for init segment
        if (!$entry['init_marked']) {
            $initPath = $dir . '/init.mp4';
            if (is_file($initPath) && filesize($initPath) > 0) {
                // Mark init segment at index -1 (convention: tier name with index -1)
                // The HTTP controller checks the file path directly for init,
                // so this is informational only. Segment availability is for media segments.
                $entry['init_marked'] = true;
            }
        }

        // Scan for new muxed segments (v{vIdx}_a{aIdx}_{tier}_{seg}.m4s).
        // The identity prefix encodes provenance; the trailing _%d is the
        // segment index the manifest/controller expects.
        $pattern = $dir . '/*.m4s';
        $files = glob($pattern) ?: [];

        foreach ($files as $file) {
            if (!is_file($file) || filesize($file) <= 0) {
                continue;
            }

            $basename = basename($file, '.m4s');
            // Match v{digits}_a{digits}_{tier}_{segDigits} — the segment index
            // is the final underscore-delimited numeric group.
            if (!preg_match('/_.*_(\d+)$/', $basename, $m)) {
                continue;
            }

            $index = (int) $m[1];

            if (isset($entry['marked'][$index])) {
                continue;
            }

            // Segment index from the HLS muxer starts at 0, but if we seeked
            // with -hls_start_number N, the muxer starts at N. We need to
            // mark the ABSOLUTE index that the manifest/controller expects.
            // The HLS muxer with -hls_start_number produces seg_N, seg_N+1, etc.
            // so the filename index IS the absolute index.
            $this->availability->markReady($jobId, $entry['tier_name'], $index, $file);
            $entry['marked'][$index] = true;

            $this->logger->debug('Segment available', [
                'jobId' => $jobKey,
                'tier' => $entry['tier_name'],
                'index' => $index,
            ]);
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

    /**
     * Count non-empty segment files in the output directory.
     *
     * Used to detect a process that exited without producing any output —
     * a failure that must be surfaced rather than treated as completion.
     */
    private function countProducedSegments(string $outputDir): int
    {
        $files = glob($outputDir . '/*.m4s') ?: [];
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file) && filesize($file) > 0) {
                $count++;
            }
        }

        return $count;
    }
}
