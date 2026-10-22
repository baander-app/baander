<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Domain\ValueObject\SubtitleTrackInfo;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Executes one durable video claim in a dedicated media child. */
final class TranscodeSessionSubscriber implements TranscodeLoopStarterInterface
{
    private const int LEASE_SECONDS = 15;

    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly TranscodeProcessPool $processPool,
        private readonly SegmentEncoder $segmentEncoder,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly LoggerInterface $logger,
        private readonly TranscodeExecutionOwnershipPort $ownership,
        private readonly TranscodeExecutionOwnershipPort $controlOwnership,
        private readonly TranscodeStreamManager $streamManager,
        private readonly LoopLockRenewalTimerInterface $renewalTimer = new SwooleLoopLockRenewalTimer(),
        private readonly ?StreamAdmissionPortInterface $admission = null,
    ) {}

    public function run(TranscodeExecutionClaim $claim, callable $stopRequested): void
    {
        $jobId = $claim->runtime->jobId;
        // Renewal uses a dedicated connection, never the aggregate connection.
        $local = new TranscodeLoopOwnership(fn(int $seconds): bool => $this->controlOwnership->renew($claim, $seconds));
        $timer = null;
        $admitted = false;
        $job = null;
        $probe = null;
        $tier = null;
        $active = $claim;
        $nativeFailure = null;
        try {
            if (!$this->processPool->isRunning() || $stopRequested() || !$local->renew(self::LEASE_SECONDS)) {
                return;
            }
            $timer = $this->startRenewal($local, $claim, $stopRequested);
            $active = $this->refresh($claim, $local, $stopRequested);
            if ($active->runtime->desired->status === 'cancelled') {
                $this->ownership->observe($active, 'cancelled');
                return;
            }
            $job = $this->jobPort->findByUuid($jobId);
            if ($job === null) {
                throw new RuntimeException('Transcode job not found.');
            }
            $tier = QualityTier::fromString($job->getQualityTierName());
            $profile = $this->segmentEncoder->getEncoderProfile();
            $this->admission?->admit($jobId, $tier->name, $tier->videoBitrate, 1080, $profile->isHardware());
            $admitted = true;
            // Pool, control timer and the execution services are initialized now.
            while (!$this->ownership->observe($active, 'accepted')) {
                $latest = $this->refresh($active, $local, $stopRequested);
                if ($latest->runtime->desired->status === 'cancelled') {
                    $active = $latest;
                    $this->ownership->observe($active, 'cancelled');
                    return;
                }
                if ($latest->runtime->desiredRevision === $active->runtime->desiredRevision
                    && $latest->runtime->generation === $active->runtime->generation) {
                    return;
                }
                $active = $latest;
            }
            $video = $this->videoRepository->findByUuid($job->getVideoId());
            if ($video === null || !is_file($video->getPath())) {
                throw new RuntimeException('Source video not found.');
            }
            $source = $video->getPath();
            $key = $this->processPool->probeVideo($job, $source, $active->runtime->generation);
            $deadline = hrtime(true) + 65_000_000_000;
            do {
                $active = $this->refresh($active, $local, $stopRequested);
                if ($active->runtime->desired->status === 'cancelled') {
                    $this->ownership->observe($active, 'cancelled');
                    return;
                }
                $result = $this->processPool->readResult($key);
                if ($result !== null) {
                    if ($result['status'] !== 'ok') {
                        throw new RuntimeException('Video probe helper failed: ' . $result['data']);
                    }
                    $probe = VideoProbeResult::fromProbeOutput(json_decode($result['data'], true, 512, JSON_THROW_ON_ERROR));
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Video probe timed out.');
                }
                Async::sleep(0.1);
            } while (true);
            $active = $this->refresh($active, $local, $stopRequested);
            $total = (int) ceil($probe->duration / SegmentEncoder::getSegmentDuration());
            $generation = null;
            $start = 0;
            $output = null;
            $scoped = null;
            $paused = false;
            $throttled = false;
            $ready = false;
            $finished = false;
            $appliedRevision = 0;
            $seen = [];
            $subtitleQueue = [];
            $subtitleActive = [];
            $subtitleDeadline = 0;
            while (true) {
                try {
                    $latest = $this->refresh($active, $local, $stopRequested);
                    if ($latest->runtime->desired->status === 'cancelled') {
                        $this->streamManager->stopStream($jobId);
                        $active = $latest;
                        $this->ownership->observe($active, 'cancelled');
                        return;
                    }
                    $playbackSegment = min(max(0, $total - 1), (int) floor($latest->runtime->desired->position / SegmentEncoder::getSegmentDuration()));
                    if ($generation !== $latest->runtime->generation) {
                        // Reap the captured process before advancing the write fence.
                        $this->streamManager->stopStream($jobId);
                        $this->processPool->shutdown();
                        $subtitleQueue = array_values(array_filter($probe->subtitleStreams, static fn (SubtitleTrackInfo $track): bool => $track->isText()));
                        $subtitleActive = [];
                        $subtitleDeadline = hrtime(true) + 30_000_000_000;
                        $active = $latest;
                        $scoped = $this->storage->forGeneration($job->getVideoId(), $tier, $active->runtime->generation, $job->getId());
                        $output = $scoped->resolveJobDirectory($job->getVideoId(), $tier);
                        $this->assertCurrent($active, $local, $stopRequested);
                        if (!is_dir($output) && !mkdir($output, 0755, true) && !is_dir($output)) {
                            throw new RuntimeException('Unable to create generation directory.');
                        }
                        $start = max(0, $playbackSegment - 3);
                        $this->writeMetadata($output, $active, $probe, $total, $start);
                        $this->persist($active, function (TranscodeJob $fresh) use ($output, $probe, $total): void {
                            $state = $fresh->getState();
                            $state->outputDirectory = $output;
                            $state->segmentMap = [];
                            $state->audioSegmentMap = [];
                            $state->completedSegments = 0;
                            $state->initSegmentPath = null;
                            if ($fresh->getStatus() === TranscodeStatus::Completed) {
                                $state->status = TranscodeStatus::InProgress;
                            }
                            if ($fresh->getStatus() === TranscodeStatus::Pending) {
                                $fresh->markInProgress();
                            }
                            $fresh->updateProbeData($probe->jsonSerialize());
                            $fresh->setTotalSegments($total);
                        });
                        $filters = $this->segmentEncoder->buildVideoFilters($probe, $tier);
                        $audioFilters = $this->segmentEncoder->buildAudioFiltersForProfile($probe, $active->runtime->desired->audioProfile);
                        $this->assertCurrent($active, $local, $stopRequested);
                        $this->streamManager->startStream($job, $source, $tier, $filters, $start, $output, $active->runtime->desired->audioProfile, $audioFilters, $active->runtime->generation, $probe->hasAudio());
                        $this->assertCurrent($active, $local, $stopRequested);
                        $generation = $active->runtime->generation;
                        $paused = $throttled = $ready = $finished = false;
                        $seen = [];
                    } else {
                        // Only after old-generation work has been retired may a new revision acknowledge effects.
                        $active = $latest;
                    }
                    $desiredPaused = $active->runtime->desired->status === 'paused';
                    $mustPause = ($desiredPaused && $ready && isset($seen[$playbackSegment])) || $throttled;
                    if ($mustPause !== $paused && !$finished) {
                        if ($mustPause) {
                            $this->streamManager->pauseStream($jobId);
                        } else {
                            $this->streamManager->resumeStream($jobId);
                        }
                        $paused = $mustPause;
                    }
                    if (!$finished) {
                        $finished = !$this->streamManager->pollOnce($jobId);
                    }
                    $this->assertCurrent($active, $local, $stopRequested);
                    if ($output === null || $scoped === null) {
                        throw new RuntimeException('Generation output has not initialized.');
                    }
                    $this->pollSubtitles($active, $job, $source, $scoped, $start * SegmentEncoder::getSegmentDuration(), $subtitleQueue, $subtitleActive, $subtitleDeadline);
                    $segments = glob($output . '/v0_a0_' . $tier->name . '_*.m4s') ?: [];
                    $new = [];
                    $highest = 0;
                    foreach ($segments as $path) {
                        if (preg_match('/_(\d+)\.m4s$/D', $path, $match) !== 1 || filesize($path) === 0) {
                            continue;
                        }
                        $index = (int) $match[1];
                        $highest = max($highest, $index);
                        if (!isset($seen[$index])) {
                            $new[$index] = ['path' => $path, 'size' => (int) filesize($path)];
                        }
                    }
                    $init = $scoped->resolveInitSegmentPath($job->getVideoId(), $tier);
                    if ($new !== []) {
                        $this->persist($active, function (TranscodeJob $fresh) use ($new, $init): void {
                            $fresh->setInitSegmentPath($init);
                            foreach ($new as $index => $segment) {
                                $fresh->markSegmentCompleted($index, $segment['path'], $segment['size'], SegmentEncoder::getSegmentDuration());
                            }
                        });
                        foreach ($new as $index => $segment) {
                            $seen[$index] = true;
                        }
                    }
                    if ((!$ready || $appliedRevision !== $active->runtime->desiredRevision)
                        && isset($seen[$playbackSegment]) && $this->completeInit($init) && $subtitleQueue === [] && $subtitleActive === []) {
                        if ($desiredPaused && !$paused && !$finished) {
                            $this->streamManager->pauseStream($jobId);
                            $paused = true;
                        }
                        // Readiness and control acknowledgement use this immutable execution fence.
                        if (!$this->ownership->observe($active, 'ready', $output)) {
                            continue;
                        }
                        $ready = true;
                        $appliedRevision = $active->runtime->desiredRevision;
                        $this->persistSessionReady($active);
                    }
                    if ($finished) {
                        if (!$ready && ($subtitleQueue !== [] || $subtitleActive !== [])) {
                            Async::sleep(0.1);
                            continue;
                        }
                        if (!$ready) {
                            throw new RuntimeException('Encoder exited before publishing playable video.');
                        }
                        $this->assertCurrent($active, $local, $stopRequested);
                        $this->streamManager->stopStream($jobId);
                        $this->persist($active, static function (TranscodeJob $fresh): void {
                            if ($fresh->getStatus() === TranscodeStatus::InProgress) {
                                $fresh->markCompleted();
                            }
                        });
                        if ($this->ownership->observe($active, 'complete', $output)) {
                            return;
                        }
                        continue;
                    }
                    $ahead = $highest - $playbackSegment;
                    if ($ahead >= 12) {
                        $throttled = true;
                    } elseif ($ahead <= 6) {
                        $throttled = false;
                    }
                    Async::sleep(0.1);
                } catch (TranscodeStreamReplaced) {
                    continue;
                }
            }
        } catch (TranscodeLoopOwnershipLost) {
            $this->logger->info('Video execution stopped after authority changed', ['jobId' => $jobId->toString()]);
        } catch (Throwable $error) {
            if ($error instanceof NativeExecutionException) {
                $nativeFailure = $error;
            }
            $this->streamManager->stopStream($jobId);
            if ($local->isActive() && !$stopRequested()) {
                try {
                    $this->persist($active, static fn(TranscodeJob $fresh) => $fresh->markFailed($error->getMessage()));
                    $this->ownership->observe($active, 'failed', error: $error->getMessage());
                } catch (TranscodeLoopOwnershipLost|TranscodeStreamReplaced) {
                }
            }
            $this->logger->error('Video execution failed', ['jobId' => $jobId->toString(), 'exception' => $error]);
        } finally {
            $local->close();
            $cleanup = [];
            if ($timer !== null) {
                $cleanup[] = fn() => $this->renewalTimer->clear($timer);
            }
            $cleanup[] = fn() => $this->streamManager->stopStream($jobId);
            $cleanup[] = fn() => $this->processPool->shutdown();
            $cleanup[] = fn() => $this->ownership->release($active);
            if ($admitted && $tier !== null) {
                $sourceHeight = $probe === null ? 1080 : $probe->height;
                $sourceCodec = $probe === null ? '' : $probe->videoCodec;
                $cleanup[] = fn() => $this->admission?->complete($jobId, $tier->name, $tier->videoBitrate, $sourceHeight, $sourceCodec, $this->segmentEncoder->getEncoderProfile()->isHardware());
            }
            foreach ($cleanup as $operation) {
                try {
                    $operation();
                } catch (Throwable $error) {
                    if ($error instanceof NativeExecutionException) {
                        $nativeFailure ??= $error;
                    }
                    $this->logger->error('Video execution cleanup failed', ['jobId' => $jobId->toString(), 'exception' => $error]);
                }
            }
            if ($nativeFailure !== null) {
                throw $nativeFailure;
            }
        }
    }

    private function startRenewal(TranscodeLoopOwnership $local, TranscodeExecutionClaim $claim, callable $stopRequested): int
    {
        $jobId = $claim->runtime->jobId;
        $nextRenewal = hrtime(true) + 3_000_000_000;
        return $this->renewalTimer->tick(250, function () use ($local, $jobId, $stopRequested, &$nextRenewal): void {
            if ($stopRequested() || !$local->isActive()) {
                $local->markLost();
                $this->streamManager->stopStream($jobId);
                return;
            }
            if (hrtime(true) >= $nextRenewal) {
                $nextRenewal = hrtime(true) + 3_000_000_000;
                if (!$local->renew(self::LEASE_SECONDS)) {
                    $this->streamManager->stopStream($jobId);
                }
            }
        });
    }

    private function refresh(TranscodeExecutionClaim $claim, TranscodeLoopOwnership $local, callable $stopRequested): TranscodeExecutionClaim
    {
        $local->assertOwned();
        if ($stopRequested()) {
            throw new TranscodeLoopOwnershipLost();
        }
        $fresh = $this->ownership->refresh($claim);
        $local->assertOwned();
        if ($fresh === null) {
            throw new TranscodeLoopOwnershipLost();
        }
        return $fresh;
    }

    private function assertCurrent(TranscodeExecutionClaim $claim, TranscodeLoopOwnership $local, callable $stopRequested): void
    {
        $fresh = $this->refresh($claim, $local, $stopRequested);
        if ($fresh->runtime->generation !== $claim->runtime->generation || $fresh->runtime->desiredRevision !== $claim->runtime->desiredRevision) {
            throw new TranscodeStreamReplaced();
        }
    }

    /** @param callable(TranscodeJob): void $operation */
    private function persist(TranscodeExecutionClaim $claim, callable $operation): void
    {
        if (!$this->ownership->withOwnership($claim, function () use ($claim, $operation): void {
            $fresh = $this->jobPort->findByUuid($claim->runtime->jobId);
            if ($fresh === null) {
                throw new RuntimeException('Transcode job disappeared.');
            }
            $operation($fresh);
            $this->jobPort->save($fresh);
        })) {
            throw new TranscodeStreamReplaced();
        }
    }

    private function persistSessionReady(TranscodeExecutionClaim $claim): void
    {
        if (!$this->ownership->withOwnership($claim, function () use ($claim): void {
            $session = $this->sessionPort->findByUuid($claim->runtime->sessionId);
            if ($session === null) {
                return;
            }
            if ($session->getSessionState() === SessionState::Pending) {
                $session->markPreparing();
            }
            if ($session->getSessionState() === SessionState::Preparing) {
                $session->markActive();
                if ($claim->runtime->desired->status === 'paused') {
                    $session->markPaused();
                }
                $this->sessionPort->save($session);
            }
        })) {
            throw new TranscodeStreamReplaced();
        }
    }

    private function completeInit(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $stat = fstat($handle);
            if ($stat === false) {
                return false;
            }
            $offset = 0;
            $found = [];
            while ($offset < $stat['size']) {
                $header = fread($handle, 8);
                if ($header === false || strlen($header) !== 8) {
                    return false;
                }
                $length = unpack('Nlength', substr($header, 0, 4));
                if ($length === false || $length['length'] < 8 || $offset + $length['length'] > $stat['size']) {
                    return false;
                }
                $found[substr($header, 4, 4)] = true;
                $offset += $length['length'];
                if (fseek($handle, $offset) !== 0) {
                    return false;
                }
            }
            return isset($found['ftyp'], $found['moov']);
        } finally {
            fclose($handle);
        }
    }

    private function writeMetadata(string $output, TranscodeExecutionClaim $claim, VideoProbeResult $probe, int $total, int $startSegment): void
    {
        $encoder = $this->segmentEncoder->getEncoderProfile()->encoder;
        $codec = match (true) {
            str_contains($encoder, '264') => 'h264',
            str_contains($encoder, '265'), str_contains($encoder, 'hevc') => 'hevc',
            str_contains($encoder, 'av1') => 'av1',
            default => throw new RuntimeException('Unknown output video codec.'),
        };
        $metadata = json_encode([
            'schemaVersion' => 1, 'generation' => $claim->runtime->generation,
            'videoCodec' => $codec, 'videoEncoder' => $encoder,
            'audioCodec' => $probe->hasAudio() ? $claim->runtime->desired->audioProfile->codec : null,
            'audioProfile' => $claim->runtime->desired->audioProfile->jsonSerialize(),
            'segmentDuration' => SegmentEncoder::getSegmentDuration(),
            'startSegment' => $startSegment,
            'startTime' => $startSegment * SegmentEncoder::getSegmentDuration(),
            'totalSegments' => $total, 'duration' => $probe->duration,
        ], JSON_THROW_ON_ERROR);
        $temporary = $output . '/generation.json.part';
        if (file_put_contents($temporary, $metadata) === false || !rename($temporary, $output . '/generation.json')) {
            throw new RuntimeException('Unable to publish generation metadata.');
        }
    }

    /**
     * @param list<SubtitleTrackInfo> $queue
     * @param array<string, SubtitleTrackInfo> $active
     */
    private function pollSubtitles(TranscodeExecutionClaim $claim, TranscodeJob $job, string $source, TranscodeStoragePortInterface $storage, float $startTime, array &$queue, array &$active, int $deadline): void
    {
        if (($queue !== [] || $active !== []) && hrtime(true) >= $deadline) {
            $this->processPool->shutdown();
            $this->logger->warning('Subtitle startup deadline expired; unfinished tracks omitted', ['jobId' => $job->getId()->toString(), 'generation' => $claim->runtime->generation, 'tracks' => count($queue) + count($active)]);
            $queue = $active = [];
            return;
        }
        foreach ($active as $key => $track) {
            $result = $this->processPool->readResult($key);
            if ($result === null) {
                continue;
            }
            unset($active[$key]);
            if ($result['status'] !== 'ok') {
                $this->logger->warning('Subtitle extraction failed; track omitted', ['jobId' => $job->getId()->toString(), 'track' => $track->trackKey(), 'error' => $result['data']]);
            }
        }
        // Three video encoders, one helper each, and one audio encoder use seven of eight native slots.
        while ($active === [] && $queue !== []) {
            $track = array_shift($queue);
            $path = $storage->resolveSubtitleSegmentPath($job->getVideoId(), $track->trackKey(), 'full');
            $key = $this->processPool->extractSubtitles($job, $source, $track->index, $path, $claim->runtime->generation, $startTime);
            $active[$key] = $track;
        }
    }
}
