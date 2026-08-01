<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Port\BudgetGuardInterface;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeJobFailed;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use App\Shared\Infrastructure\Swoole\Async;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * Listens for TranscodeSessionAttached events and orchestrates the full encoding loop.
 *
 * Runs in a Swoole coroutine (via CoWrapper::go()) to avoid blocking the HTTP worker.
 * Video segments are produced by ONE long-lived FFmpeg process per (job, tier),
 * managed by TranscodeStreamManager (Swoole\Process). The manager runs the
 * stream outside the CpuProcessPool and signals segment availability through
 * a shared Swoole Table.
 *
 * Audio init, first audio segment, loudness analysis, init segment, and subtitle
 * extraction remain on the CPU process pool (short one-shot jobs).
 * Remaining audio segments are dispatched via the pool's sliding-window path.
 *
 * Seek/throttle (KTD-3 — hybrid):
 * - Throttle: SIGSTOP/SIGCONT the long-lived process (0.38s resume, preserves
 *   amortized encoder init).
 * - Seek: kill + restart-with-headroom (SIGSTOP cannot reposition the read head;
 *   cold-start ~0.6s is imperceptible). The availability table is cleared on
 *   restart so the HTTP layer doesn't serve stale-ready rows.
 */
final class TranscodeSessionSubscriber
{
    /** Persist job/session state every N completed segments */
    private const PERSIST_INTERVAL_SEGMENTS = 10;

    /** Or every T seconds, whichever comes first */
    private const PERSIST_INTERVAL_SECONDS = 5.0;

    /** Loop lock TTL/renew cadence */
    private const LOOP_LOCK_TTL_SECONDS = 30;
    private const LOOP_LOCK_RENEW_INTERVAL_SECONDS = 20;

    /** Buffer-depth throttle thresholds (in segments ahead of playback) */
    private const THROTTLE_HIGH_SEGMENTS = 12;
    private const THROTTLE_LOW_SEGMENTS = 6;

    /** Seek restart headroom: start this many segments before the requested position */
    private const SEEK_HEADROOM_SEGMENTS = 3;

    /** @var array<string, array{count: int, time: float}> Per-job persist tracking */
    private array $persistCounters = [];

    /** @var array<string, bool> Jobs with an encoding loop running in this worker */
    private array $runningJobs = [];

    /** @var array<string, int> Swoole timer IDs for per-job lock renewal */
    private array $lockRenewTimers = [];

    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly TranscodeProcessPool $processPool,
        private readonly FFmpegPortInterface $ffmpeg,
        private readonly SegmentEncoder $segmentEncoder,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly JobStatePersister $statePersister,
        private readonly SeekSignalBroker $seekSignalBroker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly ?BudgetGuardInterface $budgetGuard = null,
        private readonly ?\SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper $coWrapper = null,
        private readonly ?TranscodeLoopLockInterface $loopLock = null,
        private readonly ?TranscodeStreamManager $streamManager = null,
        private readonly ?SegmentAvailabilityInterface $availability = null,
    )
    {
    }

    public function __invoke(TranscodeSessionAttached $event): void
    {
        if (!$this->processPool->isRunning()) {
            $this->logger->warning('CPU process pool not running — skipping transcode job');

            return;
        }

        $jobKey = $event->getJobId()->toString();
        if (isset($this->runningJobs[$jobKey])) {
            $this->logger->debug('Encoding loop already running for job, skipping duplicate attach', [
                'jobId' => $jobKey,
            ]);

            return;
        }
        $this->runningJobs[$jobKey] = true;

        if ($this->coWrapper !== null) {
            $this->coWrapper->go(function () use ($event, $jobKey): void {
                try {
                    $this->runEncodingLoop($event);
                } finally {
                    unset($this->runningJobs[$jobKey]);
                }
            });
        } else {
            try {
                $this->runEncodingLoop($event);
            } finally {
                unset($this->runningJobs[$jobKey]);
            }
        }
    }

    private function runEncodingLoop(TranscodeSessionAttached $event): void
    {
        $job = $this->jobPort->findByUuid($event->getJobId());
        if ($job === null) {
            $this->logger->error('Transcode job not found', ['jobId' => $event->getJobId()->toString()]);

            return;
        }

        if (in_array($job->getStatus(), [TranscodeStatus::Completed,
                                         TranscodeStatus::Cancelled,
                                         TranscodeStatus::Failed], true)) {
            $this->logger->debug('Job already completed or cancelled, skipping', [
                'jobId'  => $job->getId()->toString(),
                'status' => $job->getStatus()->value,
            ]);

            return;
        }

        $session = $this->loadSessionForJob($event, $job);
        if ($session === null) {
            $this->logger->warning('No active session found for job', ['jobId' => $job->getId()->toString()]);

            return;
        }

        $video = $this->videoRepository->findByUuid($job->getVideoId());
        if ($video === null) {
            $this->failJob($job, 'Source video not found');
            return;
        }

        $sourcePath = $video->getPath();
        if (!file_exists($sourcePath)) {
            $this->failJob($job, sprintf('Source file not found: %s', $sourcePath));
            return;
        }

        $tier = QualityTier::fromString($job->getQualityTierName());
        $this->seekSignalBroker->open($job->getId());
        $this->startLockRenewal($job->getId());

        try {
            $isResume = $job->getStatus() === TranscodeStatus::InProgress;

            // Step 1: Probe video (skip if resuming — data already set)
            $probe = null;
            if ($isResume && !empty($job->getProbeData())) {
                $probe = VideoProbeResult::fromSerialized($job->getProbeData());
                $totalSegments = $job->getTotalSegments();
            } else {
                $probe = $this->ffmpeg->probeVideo($sourcePath);
                $job->updateProbeData($probe->jsonSerialize());
                $totalSegments = (int)ceil($probe->duration / SegmentEncoder::getSegmentDuration());
                $job->setTotalSegments($totalSegments);
            }

            // Step 2: Mark in_progress (skip if already in_progress from resume).
            // Session transitions are state-checked: a resumed loop may attach to
            // a session left in any state by a previous run, and blind transitions
            // would throw and fail the whole job.
            if (!$isResume) {
                $job->markInProgress();
                $this->jobPort->save($job);
            }
            if ($session->getSessionState() === SessionState::Pending) {
                $session->markPreparing();
                $this->sessionPort->save($session);
            }

            $this->logger->info('Starting transcode job', [
                'jobId'         => $job->getId()->toString(),
                'videoId'       => $job->getVideoId()->toString(),
                'tier'          => $tier->name,
                'duration'      => $probe->duration,
                'totalSegments' => $totalSegments,
            ]);

            // Step 3: Build filters. The init segment is encoded with the
            // rendition's video filters so its track description (codec config,
            // dimensions) matches the media segments — MSE requires this.
            $videoFilters = $this->segmentEncoder->buildVideoFilters($probe, $tier);

            // Step 4: Init segment.
            // When the stream manager handles this tier, the init is produced by
            // the SAME muxed FFmpeg process as the segments (Step 9) — the HLS
            // muxer writes init.mp4 automatically. This is REQUIRED for muxed
            // renditions: a video-only init (from the pool) would mismatch the
            // muxed segments and MSE would reject playback.
            // When no stream manager (fallback), use the pool init encode.
            $initPath = $this->storage->resolveInitSegmentPath($job->getVideoId(), $tier);
            $useStreamManagerForInit = $this->streamManager !== null;
            if (!$useStreamManagerForInit && (!$isResume || !$this->storage->exists($initPath))) {
                $this->processPool->encodeInitSegment($job, $sourcePath, $tier, $initPath, $videoFilters);

                $initKey = CpuProcessPool::resultKey('encode_init_segment', $job->getId()->toString());
                $this->waitForResult($initKey, 120, 0.5);

                if (!file_exists($initPath)) {
                    throw new RuntimeException('Init segment encoding failed — output file not found');
                }

                $job->setInitSegmentPath($initPath);
                $this->jobPort->save($job);
            }

            // Step 5: Two-pass loudness analysis. Loudness is a property of the
            // source audio, not the rendition, and one full-file analysis takes
            // ~40s — running it per tier clogs the pool workers and starves
            // segment encoding. So a single analysis is shared by all tiers of
            // a video: the lowest-tier active job is the analysis leader — it
            // dispatches the pool job and persists the measured values on its
            // job row; sibling jobs poll the database for those values. If the
            // leader never delivers, tiers fall back to dynamic normalization.
            $measuredLoudness = $job->getMeasuredLoudness();
            if ($measuredLoudness === []) {
                $measuredLoudness = $this->findSiblingLoudness($job);
            }
            if ($measuredLoudness === []) {
                if ($this->isLoudnessLeader($job, $tier)) {
                    $loudnessFilter = AudioProcessingRules::loudnessFilter(
                        $session->getAudioProfile()->loudnessStandard,
                    );
                    $this->processPool->analyzeLoudness($sourcePath, $loudnessFilter, $job->getId()->toString());

                    // Do not block the encoding loop on a full-file loudness
                    // analysis. It is queued in the background; this session
                    // falls back to dynamic loudnorm so segment production can
                    // start immediately.
                }

                $measuredLoudness = [];
            }

            // Step 6: Build audio filters (needs the measured loudness)
            $audioFilters = $this->segmentEncoder->buildAudioFilters($probe, $session, $measuredLoudness);

            // Step 7: Mark session active.
            if ($session->getSessionState() === SessionState::Pending) {
                $session->markPreparing();
            }
            if (in_array($session->getSessionState(), [SessionState::Preparing, SessionState::Paused], true)) {
                $session->markActive();
            }
            $this->sessionPort->save($session);

            // Step 9: Start the long-lived video stream and poll until completion.
            // The stream manager spawns ONE FFmpeg process that produces all video
            // segments continuously. Seek/throttle are handled inside dispatchLongStream
            // per KTD-3 (SIGSTOP/SIGCONT for throttle, kill+restart for seek).
            $this->dispatchLongStream(
                $job, $session, $sourcePath, $tier, $videoFilters, $totalSegments,
            );

            // Step 10: Extract subtitle tracks
            $probeData = $job->getProbeData();
            $subtitleLanguages = array_column($probeData['subtitleStreams'] ?? [], 'language');
            if (!empty($subtitleLanguages)) {
                foreach ($subtitleLanguages as $language) {
                    $subtitleDir = $this->storage->resolveSubtitleDirectory($job->getVideoId(), $language);
                    if (!is_dir($subtitleDir)) {
                        mkdir($subtitleDir, 0755, true);
                    }

                    $outputPath = $this->storage->resolveSubtitleSegmentPath($job->getVideoId(), $language, 'full');
                    if (!$this->storage->exists($outputPath)) {
                        $this->processPool->extractSubtitles($job, $sourcePath, $language, $outputPath);

                        $subKey = sprintf('extract_subtitles:%s:%s', $job->getId()->toString(), $language);
                        $this->waitForResult($subKey, 120, 0.5);

                        if (!file_exists($outputPath)) {
                            $this->logger->warning(sprintf('Subtitle extraction failed for language "%s"', $language));
                            // Non-fatal — continue with other languages
                        }
                    }
                }
            }

            // Step 11: Mark completed
            if ($job->getCompletedSegments() >= $totalSegments) {
                $job->markCompleted();
                $this->forcePersistState($job, $session);
                $this->statePersister->cleanup($job->getPublicId());
                $session->markCompleted();
                $this->sessionPort->save($session);

                $this->eventDispatcher->dispatch(new TranscodeJobCompleted(
                    jobId: $job->getId(),
                    videoId: $job->getVideoId(),
                    qualityTier: $job->getQualityTierName(),
                    totalSegments: $totalSegments,
                ));

                $this->logger->info('Transcode job completed', [
                    'jobId'    => $job->getId()->toString(),
                    'segments' => $totalSegments,
                ]);
            }
        } catch (Throwable $e) {
            $this->failJob($job, $e->getMessage());
            $this->logger->error('Transcode job failed', [
                'jobId' => $job->getId()->toString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->streamManager?->stopStream($job->getId());
            $this->stopLockRenewal($job->getId());
            $this->loopLock?->release($job->getId());
            $this->clearPersistCounter($job->getId());
            $this->seekSignalBroker->close($job->getId());
        }
    }

    private function startLockRenewal(\App\Shared\Domain\Model\Uuid $jobId): void
    {
        if ($this->loopLock === null) {
            return;
        }

        $jobKey = $jobId->toString();
        $timerId = \Swoole\Timer::tick(self::LOOP_LOCK_RENEW_INTERVAL_SECONDS * 1000, function () use ($jobId, $jobKey): void {
            $renewed = $this->loopLock?->renew($jobId, self::LOOP_LOCK_TTL_SECONDS);
            if ($renewed === false) {
                $this->logger->warning('Transcode loop lock renewal failed; another worker may have taken over', [
                    'jobId' => $jobKey,
                ]);
            }
        });

        $this->lockRenewTimers[$jobKey] = $timerId;
    }

    private function stopLockRenewal(\App\Shared\Domain\Model\Uuid $jobId): void
    {
        $jobKey = $jobId->toString();
        if (isset($this->lockRenewTimers[$jobKey])) {
            \Swoole\Timer::clear($this->lockRenewTimers[$jobKey]);
            unset($this->lockRenewTimers[$jobKey]);
        }
    }

    private function loadSessionForJob(TranscodeSessionAttached $event, TranscodeJob $job): ?TranscodeSession
    {
        return $this->sessionPort->findByUuid($event->getSessionId());
    }

    private function failJob(TranscodeJob $job, string $reason): void
    {
        $job->markFailed($reason);
        $this->jobPort->save($job);
        $this->statePersister->cleanup($job->getPublicId());

        $this->eventDispatcher->dispatch(new TranscodeJobFailed(
            jobId: $job->getId(),
            videoId: $job->getVideoId(),
            reason: $reason,
        ));
    }

    private function waitForResult(string $key, int $maxWaitSeconds, float $intervalSec): void
    {
        $elapsed = 0.0;
        while ($elapsed < $maxWaitSeconds) {
            $result = $this->processPool->readResult($key);
            if ($result !== null) {
                if ($result['status'] === 'error') {
                    throw new RuntimeException($this->extractErrorMessage($result));
                }

                return;
            }

            Async::sleep($intervalSec);
            $elapsed += $intervalSec;
        }

        throw new RuntimeException(sprintf('Timed out waiting for pool result: %s', $key));
    }
    private function findSiblingLoudness(TranscodeJob $job): array
    {
        foreach ($this->jobPort->findActiveByVideo($job->getVideoId()) as $sibling) {
            if ($sibling->getId()->equals($job->getId())) {
                continue;
            }
            $siblingLoudness = $sibling->getMeasuredLoudness();
            if ($siblingLoudness !== []) {
                return $siblingLoudness;
            }
        }

        return [];
    }

    /**
     * The loudness-analysis leader is the active job with the lowest tier
     * height for the video (ties broken by job id) — deterministic across
     * workers, so exactly one tier dispatches the full-file analysis.
     */
    private function isLoudnessLeader(TranscodeJob $job, QualityTier $tier): bool
    {
        foreach ($this->jobPort->findActiveByVideo($job->getVideoId()) as $sibling) {
            if ($sibling->getId()->equals($job->getId())) {
                continue;
            }
            $siblingTier = QualityTier::fromString($sibling->getQualityTierName());
            if ($siblingTier->height < $tier->height) {
                return false;
            }
            if ($siblingTier->height === $tier->height
                && strcmp($sibling->getId()->toString(), $job->getId()->toString()) < 0) {
                return false;
            }
        }

        return true;
    }
    /**
     * Start the long-lived video stream and poll for completion, handling
     * seek/throttle signals per KTD-3.
     *
     * The stream manager spawns ONE FFmpeg process that produces all video
     * segments continuously via the HLS muxer with fMP4 fragments. The
     * subscriber polls pollOnce() to update availability and track produced
     * segments. Seek/throttle is handled in-loop:
     *
     * - Throttle: when the stream is ahead of playback by >= THROTTLE_HIGH_SEGMENTS,
     *   SIGSTOP the process. When playback catches up (<= THROTTLE_LOW_SEGMENTS),
     *   SIGCONT it. This prevents the encoder from producing segments far
     *   ahead of what the player needs.
     * - Seek (KTD-3 kill+restart): stop the stream, clear the availability
     *   table, compute startSegment = floor(position/segDur) - headroom,
     *   and start a new stream from that position.
     * - Pause: SIGSTOP the stream and mark the session paused.
     * - Resume: SIGCONT and mark the session active.
     *
     * After the stream completes (FFmpeg exits), the subscriber scans the
     * output directory for all produced segments and marks them complete on
     * the job.
     */
    private function dispatchLongStream(
        TranscodeJob $job,
        TranscodeSession $session,
        string $sourcePath,
        QualityTier $tier,
        string $videoFilters,
        int $totalSegments,
    ): void {
        if ($this->streamManager === null) {
            throw new RuntimeException('TranscodeStreamManager is not available');
        }

        $segmentDuration = SegmentEncoder::getSegmentDuration();
        $jobId = $job->getId();

        // Skip segments already on disk from a prior run (resume path)
        for ($i = 0; $i < $totalSegments; $i++) {
            if (isset($job->getSegmentMap()[(string) $i])) {
                continue;
            }
            $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $i);
            if ($this->storage->exists($outputPath) && filesize($outputPath) > 0) {
                $job->markSegmentCompleted($i, $outputPath, filesize($outputPath), $segmentDuration);
            }
        }

        if ($job->getCompletedSegments() >= $totalSegments) {
            return;
        }

        // Start the stream
        $outputDir = $this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters);

        $isPaused = false;
        $isThrottled = false;
        $lastProducedSegment = -1;
        // Real playback cursor, fed by seek/resume signals (PlaybackPositionChanged
        // events arrive via SeekSignalBroker). Driving the throttle from this —
        // not from completedSegments, which only advances after the loop — is
        // what lets the SIGSTOP/SIGCONT feedback loop actually close.
        $lastPlaybackPosition = 0.0;

        while ($this->streamManager->pollOnce($jobId)) {
            $lastProducedSegment = max($lastProducedSegment, $this->getLastProducedSegmentIndex($outputDir));

            // Check for seek/pause/resume signals
            $signal = $this->seekSignalBroker->waitForSignal($jobId, 0.1);
            if ($signal !== null) {
                // Every signal carries the latest playback position; track it
                // so the buffer-depth throttle has a real cursor to compare
                // against (fixes the P0 throttle deadlock).
                if (isset($signal['position'])) {
                    $lastPlaybackPosition = max($lastPlaybackPosition, (float) $signal['position']);
                }
                if ($signal['action'] === 'pause') {
                    $this->streamManager->pauseStream($jobId);
                    $isPaused = true;
                    $isThrottled = false;
                    $session->markPaused();
                    $this->forcePersistState($job, $session);
                    $this->logger->debug('Video stream paused', ['jobId' => $jobId->toString()]);

                    continue;
                }

                if ($signal['action'] === 'seek') {
                    // KTD-3: kill + restart-with-headroom
                    $this->streamManager->stopStream($jobId);
                    $this->availability?->clearJob($jobId);
                    $isPaused = false;
                    $isThrottled = false;

                    $targetSegment = (int) floor($signal['position'] / $segmentDuration);
                    $startSegment = max(0, $targetSegment - self::SEEK_HEADROOM_SEGMENTS);

                    $session->markResumed();
                    $this->forcePersistState($job, $session);

                    $this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters, $startSegment);
                    $lastProducedSegment = $startSegment - 1;

                    $this->logger->debug('Video stream seeked (kill+restart)', [
                        'jobId' => $jobId->toString(),
                        'position' => $signal['position'],
                        'targetSegment' => $targetSegment,
                        'startSegment' => $startSegment,
                    ]);

                    continue;
                }

                // Resume signal
                if ($isPaused || $isThrottled) {
                    $this->streamManager->resumeStream($jobId);
                    $isPaused = false;
                    $isThrottled = false;
                    if ($session->getSessionState() === SessionState::Paused) {
                        $session->markResumed();
                    }
                    $this->logger->debug('Video stream resumed', ['jobId' => $jobId->toString()]);
                }
            }

            // Skip throttle logic when explicitly paused
            if ($isPaused) {
                continue;
            }

            // Buffer-depth throttle (KTD-3 SIGSTOP/SIGCONT)
            $playbackSegment = $this->playbackPositionToSegment($lastPlaybackPosition, $segmentDuration);
            $aheadSegments = $lastProducedSegment - $playbackSegment;

            if (!$isThrottled && $aheadSegments >= self::THROTTLE_HIGH_SEGMENTS) {
                $this->streamManager->pauseStream($jobId);
                $isThrottled = true;
                $this->logger->debug('Video stream throttled (SIGSTOP)', [
                    'jobId' => $jobId->toString(),
                    'aheadSegments' => $aheadSegments,
                ]);
            } elseif ($isThrottled && $aheadSegments <= self::THROTTLE_LOW_SEGMENTS) {
                $this->streamManager->resumeStream($jobId);
                $isThrottled = false;
                $this->logger->debug('Video stream un-throttled (SIGCONT)', [
                    'jobId' => $jobId->toString(),
                    'aheadSegments' => $aheadSegments,
                ]);
            }

            // Batch persistence as segments are produced
            $producedCount = $this->countProducedSegments($outputDir);
            if ($producedCount > 0 && $producedCount % self::PERSIST_INTERVAL_SEGMENTS === 0) {
                $session->updateCurrentSegment($lastProducedSegment);
                $this->persistState($job, $session);
            }
        }

        // Stream finished — mark all produced segments complete on the job
        $this->markProducedSegmentsComplete($job, $tier, $outputDir, $segmentDuration);
        $this->forcePersistState($job, $session);
    }

    /**
     * Scan the result store for any in-flight key that has completed.
     *
     * Returns the matching key and parsed result data, or null if none found.
     * Throws immediately on error status so the encoding loop can fail the job.
     *
     * Used by the audio dispatch path (init, first segment, remaining segments)
     * which still runs through the CPU process pool.
     *
     * @param array<string, array{index: int, path: string}> $inFlight
     *
     * @return array{key: string, data: array<string, mixed>}|null
     */
    private function scanForCompletedResult(array $inFlight): ?array
    {
        foreach ($inFlight as $key => $entry) {
            $result = $this->processPool->readResult($key);
            if ($result === null) {
                continue;
            }

            if ($result['status'] === 'error') {
                throw new RuntimeException($this->extractErrorMessage($result));
            }

            if ($result['data'] === '') {
                throw new RuntimeException(sprintf(
                    'CPU pool result for key "%s" has empty data',
                    $key,
                ));
            }

            try {
                $parsed = $this->jsonEncoder->decode($result['data'], 'json');
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    'Failed to decode pool result for key "%s": %s — raw data: %s',
                    $key,
                    $e->getMessage(),
                    substr($result['data'], 0, 300),
                ));
            }

            return ['key' => $key, 'data' => is_array($parsed) ? $parsed : []];
        }

        return null;
    }

    /**
     * Determine the highest segment index produced so far in the output dir.
     *
     * Used for buffer-depth throttle calculations. The manager does not expose
     * a getter for this (U3), so we glob the output directory — segments are
     * named v{vIdx}_a{aIdx}_{tier}_{seg}.m4s, segment index is the trailing group.
     */
    private function getLastProducedSegmentIndex(string $outputDir): int
    {
        $files = glob($outputDir . '/*.m4s') ?: [];
        $max = -1;
        foreach ($files as $file) {
            if (!is_file($file) || filesize($file) <= 0) {
                continue;
            }
            if (preg_match('/_(\d+)\.m4s$/', basename($file), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    /**
     * Count the number of non-empty segment files in the output dir.
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

    /**
     * Estimate the playback segment from the job's completed-segment count.
     *
     * The SeekSignalBroker tracks playback position signals, but the subscriber
     * does not persist the exact position between polls. We approximate the
     * playback segment as the job's completed-segment count — conservative
    /**
     * Convert a playback position (seconds) to a segment index.
     *
     * Drives the buffer-depth throttle. Unlike the previous estimatePlaybackSegment
     * (which read completedSegments — a value that only advances after the poll
     * loop exits), this consumes the real playback cursor tracked from
     * PlaybackPositionChanged signals, so the SIGSTOP/SIGCONT feedback loop
     * actually closes: as the viewer watches, playbackSegment advances, the
     * ahead-gap shrinks, and a throttled stream resumes.
     */
    private function playbackPositionToSegment(float $position, float $segmentDuration): int
    {
        if ($segmentDuration <= 0.0) {
            return 0;
        }

        return (int) floor($position / $segmentDuration);
    }

    /**
     * Mark all produced segments in the output directory as complete on the job.
     *
     * Called after the long-lived FFmpeg process exits. Scans the output dir
     * for v{vIdx}_a{aIdx}_{tier}_{seg}.m4s files and marks each one complete.
     */
    private function markProducedSegmentsComplete(
        TranscodeJob $job,
        QualityTier $tier,
        string $outputDir,
        float $segmentDuration,
    ): void {
        $files = glob($outputDir . '/*.m4s') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!is_file($file) || filesize($file) <= 0) {
                continue;
            }
            if (!preg_match('/_(\d+)\.m4s$/', basename($file), $m)) {
                continue;
            }
            $index = (int) $m[1];

            // Skip already-marked segments (from resume or prior marking)
            if (isset($job->getSegmentMap()[(string) $index])) {
                continue;
            }

            $job->markSegmentCompleted($index, $file, filesize($file), $segmentDuration);
        }
    }

    private function incrementPersistCounter(Uuid $jobId): void
    {
        $key = $jobId->toString();
        if (!isset($this->persistCounters[$key])) {
            $this->persistCounters[$key] = ['count' => 0, 'time' => microtime(true)];
        }
        $this->persistCounters[$key]['count']++;
    }

    /**
     * Check if enough segments have completed (or enough time elapsed) to warrant a DB write.
     */
    private function shouldPersist(Uuid $jobId): bool
    {
        $key = $jobId->toString();
        $counter = $this->persistCounters[$key] ?? null;
        if ($counter === null) {
            return true;
        }

        if ($counter['count'] >= self::PERSIST_INTERVAL_SEGMENTS) {
            return true;
        }

        if ((microtime(true) - $counter['time']) >= self::PERSIST_INTERVAL_SECONDS) {
            return true;
        }

        return false;
    }

    /**
     * Persist job and session state (batched write).
     */
    private function persistState(TranscodeJob $job, TranscodeSession $session): void
    {
        $this->sessionPort->save($session);
        $this->jobPort->save($job);
        $this->statePersister->persist($job);

        // Reset counter
        $key = $job->getId()->toString();
        $this->persistCounters[$key] = ['count' => 0, 'time' => microtime(true)];
    }

    /**
     * Force-immediate persist — used on job completion, seek, and pause events
     * to ensure crash recovery works.
     */
    private function forcePersistState(TranscodeJob $job, TranscodeSession $session): void
    {
        $this->sessionPort->save($session);
        $this->jobPort->save($job);
        $this->statePersister->persist($job);

        // Reset counter
        $key = $job->getId()->toString();
        $this->persistCounters[$key] = ['count' => 0, 'time' => microtime(true)];
    }

    /**
     * Clean up persist counter after job finishes.
     */
    private function clearPersistCounter(Uuid $jobId): void
    {
        unset($this->persistCounters[$jobId->toString()]);
    }

    /**
     * Extract a human-readable error message from a result table row.
     *
     * The 'data' field may be a plain string (from RuntimeException::getMessage())
     * or a JSON string containing an 'error' key. Handles both cases.
     */
    private function extractErrorMessage(array $result): string
    {
        $error = $result['data'] ?? 'Unknown pool error';

        try {
            $decoded = $this->jsonEncoder->decode($error, 'json');
            if (is_array($decoded) && isset($decoded['error'])) {
                return $decoded['error'];
            }
        } catch (Throwable) {
            // $error is a plain string, use as-is
        }

        return $error;
    }
}
