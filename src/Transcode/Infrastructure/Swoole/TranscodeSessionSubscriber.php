<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Port\BudgetGuardInterface;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
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
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use App\Shared\Infrastructure\Swoole\Async;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * Listens for TranscodeSessionAttached events and orchestrates the full encoding loop.
 *
 * Runs in a Swoole coroutine (via CoWrapper::go()) to avoid blocking the HTTP worker.
 * The loop probes the source video, dispatches work to the CPU process pool (init
 * segment → loudness analysis → segments), polls the shared result table for
 * completion, and persists progress for graceful restart recovery.
 *
 * Segments use a sliding window of at most workerCount in-flight segments — as each
 * segment completes, the next is dispatched. This prevents any single job from hogging
 * the entire pool when multiple transcodes run concurrently.
 *
 * The loop is seek-aware: when a PlaybackPositionChanged event arrives (via SeekSignalBroker),
 * the segment queue is reorganized around the new playback position (closest segments first),
 * and on pause, dispatching stops while in-flight segments are allowed to finish.
 */
final class TranscodeSessionSubscriber
{
    /** Persist job/session state every N completed segments */
    private const PERSIST_INTERVAL_SEGMENTS = 10;

    /** Or every T seconds, whichever comes first */
    private const PERSIST_INTERVAL_SECONDS = 5.0;

    /** @var array<string, array{count: int, time: float}> Per-job persist tracking */
    private array $persistCounters = [];

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
        private readonly ?SegmentBookingTable $bookingTable = null,
        private readonly ?BudgetGuardInterface $budgetGuard = null,
    )
    {
    }

    public function __invoke(TranscodeSessionAttached $event): void
    {
        if (!$this->processPool->isRunning()) {
            $this->logger->warning('CPU process pool not running — skipping transcode job');

            return;
        }

        CoWrapper::go(function () use ($event): void {
            $this->runEncodingLoop($event);
        });
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

            // Step 2: Mark in_progress (skip if already in_progress from resume)
            if (!$isResume) {
                $job->markInProgress();
                $this->jobPort->save($job);
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

            // Step 3: Encode init segment (skip if resuming and already exists)
            $initPath = $this->storage->resolveInitSegmentPath($job->getVideoId(), $tier);
            if (!$isResume || !$this->storage->exists($initPath)) {
                $this->processPool->encodeInitSegment($job, $sourcePath, $tier, $initPath);

                $initKey = CpuProcessPool::resultKey('encode_init_segment', $job->getId()->toString());
                $this->waitForResult($initKey, 120, 0.5);

                if (!file_exists($initPath)) {
                    throw new RuntimeException('Init segment encoding failed — output file not found');
                }

                $job->setInitSegmentPath($initPath);
                $this->jobPort->save($job);
            }

            // Step 4: Two-pass loudness analysis (skip on resume — values
            // are persisted on the job and restored below)
            $measuredLoudness = $job->getMeasuredLoudness();
            if (!$isResume || $measuredLoudness === []) {
                $loudnessFilter = AudioProcessingRules::loudnessFilter(
                    $session->getAudioProfile()->loudnessStandard,
                );
                $this->processPool->analyzeLoudness($sourcePath, $loudnessFilter, $job->getId()->toString());

                $loudnessResult = $this->pollResult($job->getId()->toString());
                if ($loudnessResult !== null && isset($loudnessResult['loudness'])) {
                    $measuredLoudness = $loudnessResult['loudness'];
                    $job->setMeasuredLoudness($measuredLoudness);
                    $this->jobPort->save($job);
                }
            }

            // Step 5: Build filters
            $videoFilters = $this->segmentEncoder->buildVideoFilters($probe, $tier);
            $audioFilters = $this->segmentEncoder->buildAudioFilters($probe, $session, $measuredLoudness);

            // Step 6: Mark session active and dispatch segments
            $session->markActive();
            $this->sessionPort->save($session);

            $this->dispatchSegments($job, $session, $sourcePath, $tier, $videoFilters, $audioFilters, $totalSegments);

            // Step 8: Encode audio init segments for each language
            $audioProfile = $session->getAudioProfile();
            $languages = $job->getAudioTrackLanguages();
            if (!empty($languages)) {
                foreach ($languages as $language) {
                    $audioInitPath = $this->storage->resolveAudioInitSegmentPath($job->getVideoId(), $language);
                    if (!$this->storage->exists($audioInitPath)) {
                        $this->processPool->encodeAudioInitSegment(
                            $job, $sourcePath, $language, $audioProfile->jsonSerialize(), $audioInitPath,
                        );

                        $audioInitKey = sprintf('encode_audio_init_segment:%s:%s', $job->getId()->toString(), $language);
                        $this->waitForResult($audioInitKey, 120, 0.5);

                        if (!file_exists($audioInitPath)) {
                            throw new RuntimeException(sprintf('Audio init segment encoding failed for language "%s"', $language));
                        }
                    }
                }
            }

            // Step 9: Dispatch audio segments for each language
            if (!empty($languages)) {
                foreach ($languages as $language) {
                    $this->dispatchAudioSegments(
                        $job, $sourcePath, $language, $audioProfile, $audioFilters, $totalSegments,
                    );
                }
            }

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

            // Step 7: Mark completed
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
            $this->clearPersistCounter($job->getId());
            $this->seekSignalBroker->close($job->getId());
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
        $resultTable = $this->processPool->getResultTable();

        if ($resultTable === null) {
            return;
        }

        $elapsed = 0.0;
        while ($elapsed < $maxWaitSeconds) {
            if ($resultTable->exists($key)) {
                $result = $resultTable->get($key);
                $resultTable->del($key);

                if (($result['status'] ?? '') === 'error') {
                    throw new RuntimeException($this->extractErrorMessage($result));
                }

                return;
            }

            Async::sleep($intervalSec);
            $elapsed += $intervalSec;
        }

        throw new RuntimeException(sprintf('Timed out waiting for pool result: %s', $key));
    }

    private function pollResult(string $jobId): ?array
    {
        $resultTable = $this->processPool->getResultTable();
        if ($resultTable === null) {
            return null;
        }

        $key = CpuProcessPool::resultKey('analyze_loudness', $jobId);

        for ($i = 0; $i < 60; $i++) {
            if ($resultTable->exists($key)) {
                $result = $resultTable->get($key);
                $resultTable->del($key);

                if (($result['status'] ?? '') === 'ok') {
                    return $this->jsonEncoder->decode($result['data'], 'json');
                }

                return null;
            }

            Async::sleep(0.5);
        }

        return null;
    }

    /**
     * Dispatch audio segments with sliding window, matching the video segment pattern.
     *
     * @param string $language BCP-47 language tag
     */
    private function dispatchAudioSegments(
        TranscodeJob $job,
        string $sourcePath,
        string $language,
        AudioProfile $audioProfile,
        string $audioFilters,
        int $totalSegments,
    ): void {
        $segmentDuration = SegmentEncoder::getSegmentDuration();
        $audioProfileData = $audioProfile->jsonSerialize();
        $resultTable = $this->processPool->getResultTable();

        // Build pending list, skipping already-completed audio segments
        $pendingKeys = [];
        $pending = [];
        $audioSegmentMap = $job->getAudioSegmentMap();

        for ($i = 0; $i < $totalSegments; $i++) {
            $key = sprintf('%s:%d', $language, $i);
            if (isset($audioSegmentMap[$key])) {
                continue;
            }
            $outputPath = $this->storage->resolveAudioSegmentPath($job->getVideoId(), $language, $i);
            if ($this->storage->exists($outputPath)) {
                $fileSize = filesize($outputPath);
                if ($fileSize > 0) {
                    $job->markAudioSegmentCompleted($language, $i, $outputPath, $fileSize, $segmentDuration);
                    continue;
                }
            }
            $pendingKeys[] = $i;
            $pending[] = $outputPath;
        }

        if ($pendingKeys === []) {
            return;
        }

        $dispatched = 0;
        $total = count($pendingKeys);
        $windowSize = $this->processPool->getWorkerCount(); // Video encoding completed in Step 6, full pool available
        $inFlight = [];

        while ($dispatched < $total || $inFlight !== []) {
            // Fill window
            while ($dispatched < $total && count($inFlight) < $windowSize) {
                $segmentIndex = $pendingKeys[$dispatched];
                $outputPath = $pending[$dispatched];
                $startTime = (float) ($segmentIndex * $segmentDuration);

                $this->processPool->encodeAudioSegment(
                    $job, $segmentIndex, $sourcePath, $startTime, $segmentDuration,
                    $audioProfileData, $audioFilters, $language, $outputPath,
                );

                $key = sprintf('encode_audio_segment:%s:%s:%d', $job->getId()->toString(), $language, $segmentIndex);
                $inFlight[$key] = ['index' => $segmentIndex, 'path' => $outputPath];
                $dispatched++;
            }

            // Poll for completion
            if ($inFlight !== []) {
                $completed = $this->scanForCompletedResult($inFlight, $resultTable);
                if ($completed !== null) {
                    $entry = $inFlight[$completed['key']];
                    unset($inFlight[$completed['key']]);

                    $duration = (float) ($completed['data']['duration'] ?? $segmentDuration);
                    $fileSize = file_exists($entry['path']) ? filesize($entry['path']) : 0;
                    if ($fileSize > 0) {
                        $job->markAudioSegmentCompleted($language, $entry['index'], $entry['path'], $fileSize, $duration);

                        $this->incrementPersistCounter($job->getId());
                        if ($this->shouldPersist($job->getId())) {
                            $this->jobPort->save($job);
                        }
                    }
                }
            }

            if ($inFlight === [] && $dispatched >= $total) {
                break;
            }

            Async::sleep(0.1);
        }

        // Always persist on completion of all audio segments
        $this->jobPort->save($job);
        $this->statePersister->persist($job);
        $this->clearPersistCounter($job->getId());
    }

    /**
     * Dispatch segments with seek-aware queue management.
     *
     * Uses a Set of in-flight result keys instead of a FIFO Channel,
     * so any segment can complete and be processed regardless of dispatch
     * order — achieving true multi-worker parallelism.
     *
     * On pause: stops dispatching new segments, drains in-flight only.
     * On seek: reorganizes remaining segments by distance from new position.
     * In-flight segments always finish — workers are never killed.
     */
    private function dispatchSegments(
        TranscodeJob $job,
        TranscodeSession $session,
        string $sourcePath,
        QualityTier $tier,
        string $videoFilters,
        string $audioFilters,
        int $totalSegments,
    ): void
    {
        $segmentDuration = SegmentEncoder::getSegmentDuration();
        $windowSize = $this->processPool->getWorkerCount();
        $resultTable = $this->processPool->getResultTable();

        // Build initial pending list (sequential order, skipping completed)
        $pendingKeys = [];
        $pending = [];
        for ($i = 0; $i < $totalSegments; $i++) {
            if (isset($job->getSegmentMap()[(string)$i])) {
                continue;
            }
            $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $i);
            if ($this->storage->exists($outputPath)) {
                $fileSize = filesize($outputPath);
                if ($fileSize > 0) {
                    $job->markSegmentCompleted($i, $outputPath, $fileSize, $segmentDuration);
                    continue;
                }
            }
            $pendingKeys[] = $i;
            $pending[] = $outputPath;
        }

        if ($pendingKeys === []) {
            return;
        }

        $dispatched = 0;
        $total = count($pendingKeys);
        $isPaused = false;

        /** @var array<string, array{index: int, path: string}> $inFlight */
        $inFlight = [];

        while ($dispatched < $total || $inFlight !== []) {
            // Check for seek/pause signals (drain all, act on latest)
            $signal = $this->seekSignalBroker->waitForSignal($job->getId(), 0.5);
            if ($signal !== null) {
                if ($signal['action'] === 'pause') {
                    $isPaused = true;
                    $session->markPaused();
                    $this->forcePersistState($job, $session);
                    $this->logger->debug('Encoding paused', ['jobId' => $job->getId()->toString()]);
                } elseif ($signal['action'] === 'seek') {
                    $isPaused = false;
                    $session->markResumed();
                    $this->forcePersistState($job, $session);

                    $targetSegment = (int)floor($signal['position'] / $segmentDuration);
                    $this->reorganizeQueue(
                        $job, $tier, $totalSegments, $segmentDuration,
                        $inFlight, $pendingKeys, $pending, $dispatched, $total,
                        $targetSegment,
                    );
                    $dispatched = 0;
                    $total = count($pendingKeys);

                    $this->logger->debug('Encoding seeked', [
                        'jobId'         => $job->getId()->toString(),
                        'targetSegment' => $targetSegment,
                        'remaining'     => $total,
                    ]);
                }
            }

            // When paused, only drain in-flight segments
            if ($isPaused) {
                if ($inFlight !== []) {
                    $completed = $this->scanForCompletedResult($inFlight, $resultTable);
                    if ($completed !== null) {
                        $entry = $inFlight[$completed['key']];
                        $entry['metrics'] = $completed['data']['metrics'] ?? [];
                        unset($inFlight[$completed['key']]);
                        $this->processSegmentResult($job, $session, $entry, $segmentDuration);
                        $dispatched++;
                    }
                }
                continue;
            }

            // Fill the window up to capacity
            while ($dispatched < $total && count($inFlight) < $windowSize) {
                $segmentIndex = $pendingKeys[$dispatched];
                $outputPath = $pending[$dispatched];
                $startTime = $segmentIndex * $segmentDuration;

                // Skip if already booked by prefetcher
                if ($this->bookingTable !== null && $this->bookingTable->isBooked($job->getId(), $segmentIndex)) {
                    // Already in-flight via prefetcher — skip but still track as dispatched
                    $dispatched++;
                    continue;
                }

                $this->budgetGuard?->guardDispatch($job->getId());

                $this->processPool->encodeSegment(
                    job: $job,
                    segmentIndex: $segmentIndex,
                    sourcePath: $sourcePath,
                    startTime: $startTime,
                    tier: $tier,
                    audioProfile: $session->getAudioProfile()->jsonSerialize(),
                    videoFilters: $videoFilters,
                    audioFilters: $audioFilters,
                    outputPath: $outputPath,
                );

                // Book segment to coordinate with prefetcher
                $this->bookingTable?->book($job->getId(), $segmentIndex, 'encoder');

                $key = CpuProcessPool::resultKey('encode_segment', $job->getId()->toString(), $segmentIndex);
                $inFlight[$key] = ['index' => $segmentIndex, 'path' => $outputPath];
                $dispatched++;
            }

            // Poll for any completed segment (non-blocking)
            if ($inFlight !== []) {
                $completed = $this->scanForCompletedResult($inFlight, $resultTable);
                if ($completed !== null) {
                    $entry = $inFlight[$completed['key']];
                    $entry['metrics'] = $completed['data']['metrics'] ?? [];
                    unset($inFlight[$completed['key']]);
                    $this->processSegmentResult($job, $session, $entry, $segmentDuration);
                }
            }
        }
    }

    /**
     * Scan the result table for any in-flight key that has completed.
     *
     * Returns the matching key and parsed result data, or null if none found.
     * Throws immediately on error status so the encoding loop can fail the job.
     *
     * @param array<string, array{index: int, path: string}> $inFlight
     *
     * @return array{key: string, data: array<string, mixed>}|null
     */
    private function scanForCompletedResult(
        array $inFlight,
        ?\Swoole\Table $resultTable,
    ): ?array
    {
        foreach ($inFlight as $key => $entry) {
            if ($resultTable !== null && $resultTable->exists($key)) {
                $result = $resultTable->get($key);
                $resultTable->del($key);

                if (($result['status'] ?? '') === 'error') {
                    throw new RuntimeException($this->extractErrorMessage($result));
                }

                $parsed = $this->jsonEncoder->decode($result['data'], 'json');

                return ['key' => $key, 'data' => is_array($parsed) ? $parsed : []];
            }
        }

        return null;
    }

    /**
     * Reorganize the pending segment queue around a seek target.
     *
     * Collects all segments not yet completed and not currently in-flight,
     * sorts them by distance from the target segment (closest first),
     * and rebuilds the pending arrays.
     *
     * @param array<string, array{index: int, path: string}> $inFlight
     */
    private function reorganizeQueue(
        TranscodeJob $job,
        QualityTier $tier,
        int $totalSegments,
        float $segmentDuration,
        array &$inFlight,
        array &$pendingKeys,
        array &$pending,
        int &$dispatched,
        int &$total,
        int $targetSegment,
    ): void
    {
        $inFlightIndices = array_map(fn(array $entry) => $entry['index'], $inFlight);

        // Collect remaining segments (not completed, not in-flight)
        $remaining = [];
        for ($i = 0; $i < $totalSegments; $i++) {
            if (isset($job->getSegmentMap()[(string)$i])) {
                continue;
            }
            if (in_array($i, $inFlightIndices, true)) {
                continue;
            }
            $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $i);
            $remaining[] = ['index' => $i, 'path' => $outputPath];
        }

        // Sort by distance from seek target (closest first)
        usort($remaining, fn($a, $b) => abs($a['index'] - $targetSegment) <=> abs($b['index'] - $targetSegment));

        $pendingKeys = array_column($remaining, 'index');
        $pending = array_column($remaining, 'path');
        $dispatched = 0;
        $total = count($remaining);
    }

    /**
     * Process a completed segment result — persist progress, metrics, and state.
     *
     * @param array{index: int, path: string, metrics?: array<string, mixed>} $result
     */
    private function processSegmentResult(TranscodeJob $job, TranscodeSession $session, array $result, float $segmentDuration): void
    {
        $index = $result['index'];

        $this->waitForSegmentResult($job, $index, $result['path']);

        // Release booking table entry regardless of success/failure
        $this->bookingTable?->release($job->getId(), $index);

        $session->updateCurrentSegment($index);

        // Persist encoding metrics from the pool worker
        $metrics = $result['metrics'] ?? [];
        if (!empty($metrics)) {
            $session->updateMetrics($metrics);
        }

        $fileSize = file_exists($result['path']) ? filesize($result['path']) : 0;
        if ($fileSize > 0) {
            $job->markSegmentCompleted($index, $result['path'], $fileSize, $segmentDuration);
        }

        // Batch persistence — only write to DB every N segments or T seconds
        $this->incrementPersistCounter($job->getId());
        if ($fileSize > 0 && $this->shouldPersist($job->getId())) {
            $this->persistState($job, $session);
        }
    }

    /**
     * Increment the per-job segment counter for batch persistence.
     */
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

    private function waitForSegmentResult(TranscodeJob $job, int $segmentIndex, string $outputPath): void
    {
        $maxWait = 300;
        $interval = 0.5;

        $key = CpuProcessPool::resultKey('encode_segment', $job->getId()->toString(), $segmentIndex);

        $resultTable = $this->processPool->getResultTable();

        if ($resultTable === null) {
            $this->waitForFile($outputPath, $maxWait, $interval);

            return;
        }

        $elapsed = 0.0;
        while ($elapsed < $maxWait) {
            if ($resultTable->exists($key)) {
                $result = $resultTable->get($key);
                $resultTable->del($key);

                if (($result['status'] ?? '') === 'error') {
                    throw new RuntimeException($this->extractErrorMessage($result));
                }

                return;
            }

            Async::sleep($interval);
            $elapsed += $interval;
        }

        throw new RuntimeException(sprintf('Segment %d timed out waiting for pool result', $segmentIndex));
    }

    private function waitForFile(string $path, int $maxWait, float $interval): void
    {
        $elapsed = 0.0;
        while (!file_exists($path) && $elapsed < $maxWait) {
            Async::sleep($interval);
            $elapsed += $interval;
        }

        if (!file_exists($path)) {
            throw new RuntimeException(sprintf('Timed out waiting for file: %s', $path));
        }
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
