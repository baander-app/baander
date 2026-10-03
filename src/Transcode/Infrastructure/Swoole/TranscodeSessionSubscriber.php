<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
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

    /** Loop lock TTL/renew cadence */
    private const LOOP_LOCK_TTL_SECONDS = 30;
    private const LOOP_LOCK_RENEW_INTERVAL_SECONDS = 20;

    /** Buffer-depth throttle thresholds (in segments ahead of playback) */
    private const THROTTLE_HIGH_SEGMENTS = 12;
    private const THROTTLE_LOW_SEGMENTS = 6;

    /** Seek restart headroom: start this many segments before the requested position */
    private const SEEK_HEADROOM_SEGMENTS = 3;

    /** @var array<string, bool> Jobs with an encoding loop running in this worker */
    private array $runningJobs = [];

    /** @var array<string, int> Swoole timer IDs for per-job lock renewal */
    private array $lockRenewTimers = [];

    /** @var array<string, TranscodeLoopOwnership> */
    private array $loopOwnership = [];

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
        private readonly ?\SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper $coWrapper = null,
        private readonly ?TranscodeLoopLockInterface $loopLock = null,
        private readonly ?TranscodeStreamManager $streamManager = null,
        private readonly ?SegmentAvailabilityInterface $availability = null,
        private readonly LoopLockRenewalTimerInterface $renewalTimer = new SwooleLoopLockRenewalTimer(),
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
        $jobId = $event->getJobId();
        $jobKey = $jobId->toString();
        $ownership = new TranscodeLoopOwnership();
        $this->loopOwnership[$jobKey] = $ownership;
        $job = null;

        try {
            $this->startLockRenewal($jobId, $ownership);
            $job = $this->jobPort->findByUuid($event->getJobId());
            $this->assertLoopOwnership($jobId, $ownership);
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
            $this->assertLoopOwnership($jobId, $ownership);
            if ($session === null) {
                $this->logger->warning('No active session found for job', ['jobId' => $job->getId()->toString()]);

                return;
            }

            $video = $this->videoRepository->findByUuid($job->getVideoId());
            $this->assertLoopOwnership($jobId, $ownership);
            if ($video === null) {
                $this->failJob($job, 'Source video not found', $ownership);
                return;
            }

            $sourcePath = $video->getPath();
            if (!file_exists($sourcePath)) {
                $this->failJob($job, sprintf('Source file not found: %s', $sourcePath), $ownership);
                return;
            }

            $tier = QualityTier::fromString($job->getQualityTierName());
            $this->seekSignalBroker->open($job->getId());

            $isResume = $job->getStatus() === TranscodeStatus::InProgress;

            // Step 1: Probe video (skip if resuming — data already set)
            $probe = null;
            if ($isResume && !empty($job->getProbeData())) {
                $probe = VideoProbeResult::fromSerialized($job->getProbeData());
                $totalSegments = $job->getTotalSegments();
            } else {
                $probe = $this->ffmpeg->probeVideo($sourcePath);
                $this->assertLoopOwnership($jobId, $ownership);
                $job->updateProbeData($probe->jsonSerialize());
                $totalSegments = (int)ceil($probe->duration / SegmentEncoder::getSegmentDuration());
                $job->setTotalSegments($totalSegments);
            }

            // Step 2: Mark in_progress (skip if already in_progress from resume).
            // Session transitions are state-checked: a resumed loop may attach to
            // a session left in any state by a previous run, and blind transitions
            // would throw and fail the whole job.
            if (!$isResume) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $job->markInProgress();
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->jobPort->save($job);
                $this->assertLoopOwnership($job->getId(), $ownership);
            }
            if ($session->getSessionState() === SessionState::Pending) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $session->markPreparing();
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->sessionPort->save($session);
                $this->assertLoopOwnership($job->getId(), $ownership);
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
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->processPool->encodeInitSegment($job, $sourcePath, $tier, $initPath, $videoFilters);
                $this->assertLoopOwnership($job->getId(), $ownership);

                $initKey = CpuProcessPool::resultKey('encode_init_segment', $job->getId()->toString());
                $this->waitForResult($jobId, $initKey, 120, 0.5, $ownership);

                if (!file_exists($initPath)) {
                    throw new RuntimeException('Init segment encoding failed — output file not found');
                }

                $this->assertLoopOwnership($jobId, $ownership);
                $job->setInitSegmentPath($initPath);
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->jobPort->save($job);
                $this->assertLoopOwnership($job->getId(), $ownership);
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
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->processPool->analyzeLoudness($sourcePath, $loudnessFilter, $job->getId()->toString());
                    $this->assertLoopOwnership($job->getId(), $ownership);

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
                $this->assertLoopOwnership($job->getId(), $ownership);
                $session->markPreparing();
            }
            if (in_array($session->getSessionState(), [SessionState::Preparing, SessionState::Paused], true)) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $session->markActive();
            }
            $this->assertLoopOwnership($job->getId(), $ownership);
            $this->sessionPort->save($session);
            $this->assertLoopOwnership($job->getId(), $ownership);

            // Step 9: Start the long-lived video stream and poll until completion.
            // The stream manager spawns ONE FFmpeg process that produces all video
            // segments continuously. Seek/throttle are handled inside dispatchLongStream
            // per KTD-3 (SIGSTOP/SIGCONT for throttle, kill+restart for seek).
            $this->dispatchLongStream(
                $job, $session, $sourcePath, $tier, $videoFilters, $totalSegments, $ownership,
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
                        $this->assertLoopOwnership($job->getId(), $ownership);
                        $this->processPool->extractSubtitles($job, $sourcePath, $language, $outputPath);
                        $this->assertLoopOwnership($job->getId(), $ownership);

                        $subKey = sprintf('extract_subtitles:%s:%s', $job->getId()->toString(), $language);
                        $this->waitForResult($jobId, $subKey, 120, 0.5, $ownership);

                        if (!file_exists($outputPath)) {
                            $this->logger->warning(sprintf('Subtitle extraction failed for language "%s"', $language));
                            // Non-fatal — continue with other languages
                        }
                    }
                }
            }

            // Step 11: Mark completed
            if ($job->getCompletedSegments() >= $totalSegments) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $job->markCompleted();
                $this->forcePersistState($job, $session, $ownership);
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->statePersister->cleanup($job->getPublicId());
                $this->assertLoopOwnership($job->getId(), $ownership);
                $session->markCompleted();
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->sessionPort->save($session);
                $this->assertLoopOwnership($job->getId(), $ownership);

                $this->assertLoopOwnership($jobId, $ownership);
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
        } catch (TranscodeLoopOwnershipLost $e) {
            $this->logger->warning('Transcode loop stopped after ownership loss', ['jobId' => $jobKey]);
        } catch (Throwable $e) {
            // Stopping the process from the renewal callback can surface an
            // ordinary FFmpeg/I/O error in the suspended encoding coroutine.
            // Never turn that error into a stale job failure write.
            if ($this->ownsLoop($jobId, $ownership)) {
                try {
                    if ($job !== null) {
                        $this->failJob($job, $e->getMessage(), $ownership);
                    }
                } catch (TranscodeLoopOwnershipLost) {
                    // Ownership can also be lost during failure persistence.
                }
                $this->logger->error('Transcode job failed', [
                    'jobId' => $jobKey,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            $this->cleanupLoop($jobId, $ownership);
        }
    }

    private function startLockRenewal(Uuid $jobId, TranscodeLoopOwnership $ownership): void
    {
        if ($this->loopLock === null) {
            return;
        }

        // The lease may have expired between session creation and execution.
        $this->renewLoopLock($jobId, $ownership);
        $this->assertLoopOwnership($jobId, $ownership);
        $this->lockRenewTimers[$jobId->toString()] = $this->renewalTimer->tick(
            self::LOOP_LOCK_RENEW_INTERVAL_SECONDS * 1000,
            function () use ($jobId, $ownership): void {
                $this->renewLoopLock($jobId, $ownership);
            },
        );
    }

    private function renewLoopLock(Uuid $jobId, TranscodeLoopOwnership $ownership): void
    {
        if (!$this->ownsLoop($jobId, $ownership)) {
            return;
        }
        try {
            $renewed = $this->loopLock?->renew($jobId, self::LOOP_LOCK_TTL_SECONDS);
        } catch (Throwable) {
            $renewed = false;
        }
        // Redis I/O may have yielded to cleanup or a replacement loop.
        if (!$this->ownsLoop($jobId, $ownership) || $renewed === true) {
            return;
        }
        $ownership->markLost();
        try {
            $this->streamManager?->stopStream($jobId);
        } catch (Throwable $error) {
            $this->logger->error('Could not stop encoder after ownership loss', [
                'jobId' => $jobId->toString(), 'error' => $error->getMessage(),
            ]);
        }
        $this->logger->warning('Transcode loop ownership lost; stopping local work', [
            'jobId' => $jobId->toString(),
        ]);
    }

    /** Coroutine callbacks can change this state while Redis or filesystem I/O yields.
     * @phpstan-impure
     */
    private function ownsLoop(Uuid $jobId, TranscodeLoopOwnership $ownership): bool
    {
        return ($this->loopOwnership[$jobId->toString()] ?? null) === $ownership && $ownership->isActive();
    }

    private function assertLoopOwnership(Uuid $jobId, TranscodeLoopOwnership $ownership): void
    {
        if (($this->loopOwnership[$jobId->toString()] ?? null) !== $ownership) {
            throw new TranscodeLoopOwnershipLost();
        }
        $ownership->assertOwned();
    }

    private function stopLockRenewal(Uuid $jobId): void
    {
        $jobKey = $jobId->toString();
        if (isset($this->lockRenewTimers[$jobKey])) {
            $timerId = $this->lockRenewTimers[$jobKey];
            unset($this->lockRenewTimers[$jobKey]);
            $this->renewalTimer->clear($timerId);
        }
    }

    private function cleanupLoop(Uuid $jobId, TranscodeLoopOwnership $ownership): void
    {
        $jobKey = $jobId->toString();
        if (($this->loopOwnership[$jobKey] ?? null) !== $ownership) {
            return;
        }
        $ownership->close();
        // Cleanup must continue if a timer, process or transport cleanup fails.
        $actions = [
            fn() => $this->stopLockRenewal($jobId),
            fn() => $this->streamManager?->stopStream($jobId),
            function () use ($jobId, $ownership): void {
                if (!$ownership->isLost()) {
                    $this->loopLock?->release($jobId);
                }
            },
            fn() => $this->seekSignalBroker->close($jobId),
        ];
        foreach ($actions as $action) {
            if (($this->loopOwnership[$jobKey] ?? null) !== $ownership) {
                return;
            }
            try {
                $action();
            } catch (Throwable $error) {
                $this->logger->error('Transcode loop cleanup failed', [
                    'jobId' => $jobKey, 'error' => $error->getMessage(),
                ]);
            }
        }
        if (($this->loopOwnership[$jobKey] ?? null) === $ownership) {
            unset($this->loopOwnership[$jobKey]);
        }
    }

    private function loadSessionForJob(TranscodeSessionAttached $event, TranscodeJob $job): ?TranscodeSession
    {
        return $this->sessionPort->findByUuid($event->getSessionId());
    }

    private function failJob(TranscodeJob $job, string $reason, TranscodeLoopOwnership $ownership): void
    {
        $this->assertLoopOwnership($job->getId(), $ownership);
        $job->markFailed($reason);
        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->jobPort->save($job);
        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->statePersister->cleanup($job->getPublicId());
        $this->assertLoopOwnership($job->getId(), $ownership);

        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->eventDispatcher->dispatch(new TranscodeJobFailed(
            jobId: $job->getId(),
            videoId: $job->getVideoId(),
            reason: $reason,
        ));
    }

    private function waitForResult(Uuid $jobId, string $key, int $maxWaitSeconds, float $intervalSec, TranscodeLoopOwnership $ownership): void
    {
        $elapsed = 0.0;
        while ($elapsed < $maxWaitSeconds) {
            $this->assertLoopOwnership($jobId, $ownership);
            $result = $this->processPool->readResult($key);
            $this->assertLoopOwnership($jobId, $ownership);
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
    /** @return array<string, mixed> */
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
        TranscodeLoopOwnership $ownership,
    ): void {
        if ($this->streamManager === null) {
            throw new RuntimeException('TranscodeStreamManager is not available');
        }

        $segmentDuration = SegmentEncoder::getSegmentDuration();
        $jobId = $job->getId();

        // Skip segments already on disk from a prior run (resume path)
        for ($i = 0; $i < $totalSegments; $i++) {
            if (array_key_exists((string) $i, $job->getSegmentMap())) {
                continue;
            }
            $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $i);
            $exists = $this->storage->exists($outputPath);
            $this->assertLoopOwnership($jobId, $ownership);
            if ($exists) {
                $size = filesize($outputPath);
                $this->assertLoopOwnership($jobId, $ownership);
                if ($size > 0) {
                    $job->markSegmentCompleted($i, $outputPath, $size, $segmentDuration);
                }
            }
        }

        if ($job->getCompletedSegments() >= $totalSegments) {
            return;
        }

        // Start the stream
        $this->assertLoopOwnership($job->getId(), $ownership);
        $outputDir = $this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters);
        $this->assertLoopOwnership($job->getId(), $ownership);

        $isPaused = false;
        $isThrottled = false;
        $lastProducedSegment = -1;
        // Real playback cursor, fed by seek/resume signals (PlaybackPositionChanged
        // events arrive via SeekSignalBroker). Driving the throttle from this —
        // not from completedSegments, which only advances after the loop — is
        // what lets the SIGSTOP/SIGCONT feedback loop actually close.
        $lastPlaybackPosition = 0.0;

        while (true) {
            $this->assertLoopOwnership($jobId, $ownership);
            $running = $this->streamManager->pollOnce($jobId);
            $this->assertLoopOwnership($jobId, $ownership);
            if (!$running) {
                break;
            }
            $lastProducedSegment = max($lastProducedSegment, $this->getLastProducedSegmentIndex($outputDir));

            // Check for seek/pause/resume signals
            $signal = $this->seekSignalBroker->waitForSignal($jobId, 0.1);
            $this->assertLoopOwnership($jobId, $ownership);
            if ($signal !== null) {
                // Every signal carries the latest playback position; track it
                // so the buffer-depth throttle has a real cursor to compare
                // against (fixes the P0 throttle deadlock).
                $lastPlaybackPosition = max($lastPlaybackPosition, $signal['position']);
                if ($signal['action'] === 'pause') {
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->streamManager->pauseStream($jobId);
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $isPaused = true;
                    $isThrottled = false;
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $session->markPaused();
                    $this->forcePersistState($job, $session, $ownership);
                    $this->logger->debug('Video stream paused', ['jobId' => $jobId->toString()]);

                    continue;
                }

                if ($signal['action'] === 'seek') {
                    // KTD-3: kill + restart-with-headroom
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->streamManager->stopStream($jobId);
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->availability?->clearJob($jobId);
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $isPaused = false;
                    $isThrottled = false;

                    $targetSegment = (int) floor($signal['position'] / $segmentDuration);
                    $startSegment = max(0, $targetSegment - self::SEEK_HEADROOM_SEGMENTS);

                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $session->markResumed();
                    $this->forcePersistState($job, $session, $ownership);

                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters, $startSegment);
                    $this->assertLoopOwnership($job->getId(), $ownership);
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
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $this->streamManager->resumeStream($jobId);
                    $this->assertLoopOwnership($job->getId(), $ownership);
                    $isPaused = false;
                    $isThrottled = false;
                    if ($session->getSessionState() === SessionState::Paused) {
                        $this->assertLoopOwnership($job->getId(), $ownership);
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
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->streamManager->pauseStream($jobId);
                $this->assertLoopOwnership($job->getId(), $ownership);
                $isThrottled = true;
                $this->logger->debug('Video stream throttled (SIGSTOP)', [
                    'jobId' => $jobId->toString(),
                    'aheadSegments' => $aheadSegments,
                ]);
            } elseif ($isThrottled && $aheadSegments <= self::THROTTLE_LOW_SEGMENTS) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $this->streamManager->resumeStream($jobId);
                $this->assertLoopOwnership($job->getId(), $ownership);
                $isThrottled = false;
                $this->logger->debug('Video stream un-throttled (SIGCONT)', [
                    'jobId' => $jobId->toString(),
                    'aheadSegments' => $aheadSegments,
                ]);
            }

            // Batch persistence as segments are produced
            $producedCount = $this->countProducedSegments($outputDir);
            if ($producedCount > 0 && $producedCount % self::PERSIST_INTERVAL_SEGMENTS === 0) {
                $this->assertLoopOwnership($job->getId(), $ownership);
                $session->updateCurrentSegment($lastProducedSegment);
                $this->persistState($job, $session, $ownership);
            }
        }

        // Stream finished — mark all produced segments complete on the job
        $this->assertLoopOwnership($jobId, $ownership);
        $this->markProducedSegmentsComplete($job, $tier, $outputDir, $segmentDuration, $ownership);
        $this->forcePersistState($job, $session, $ownership);
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
        TranscodeLoopOwnership $ownership,
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
            if (array_key_exists((string) $index, $job->getSegmentMap())) {
                continue;
            }

            $size = filesize($file);
            $this->assertLoopOwnership($job->getId(), $ownership);
            $job->markSegmentCompleted($index, $file, $size, $segmentDuration);
        }
    }

    /**
     * Persist job and session state (batched write).
     */
    private function persistState(TranscodeJob $job, TranscodeSession $session, TranscodeLoopOwnership $ownership): void
    {
        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->sessionPort->save($session);
        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->jobPort->save($job);
        $this->assertLoopOwnership($job->getId(), $ownership);
        $this->statePersister->persist($job);
        $this->assertLoopOwnership($job->getId(), $ownership);
    }

    /**
     * Force-immediate persist — used on job completion, seek, and pause events
     * to ensure crash recovery works.
     */
    private function forcePersistState(TranscodeJob $job, TranscodeSession $session, TranscodeLoopOwnership $ownership): void
    {
        $this->persistState($job, $session, $ownership);
    }

    /**
     * Extract a human-readable error message from a result table row.
     *
     * The 'data' field may be a plain string (from RuntimeException::getMessage())
     * or a JSON string containing an 'error' key. Handles both cases.
     * @param array{data: string, status: string} $result
     */
    private function extractErrorMessage(array $result): string
    {
        $error = $result['data'];

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
