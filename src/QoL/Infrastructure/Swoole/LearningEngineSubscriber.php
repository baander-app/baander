<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listens for TranscodeJobCompleted events and records utilization samples
 * for the learning model. Loads completed job data (probe data, quality tier)
 * to create rich training samples.
 *
 * Uses CoWrapper::go() to avoid blocking the HTTP worker during DB access.
 */
final class LearningEngineSubscriber
{
    public function __construct(
        private readonly StreamGovernor             $governor,
        private readonly TranscodeJobPortInterface  $jobPort,
        private readonly CpuGpuSampler              $sampler,
        private readonly LearningDataPersister      $persister,
        private readonly HardwareCapabilitiesProber $prober,
        private readonly LoggerInterface            $logger,
    )
    {
    }

    #[AsEventListener(event: TranscodeJobCompleted::class)]
    public function __invoke(TranscodeJobCompleted $event): void
    {
        CoWrapper::go(function () use ($event): void {
            $this->recordSample($event);
        });
    }

    private function recordSample(TranscodeJobCompleted $event): void
    {
        $job = $this->jobPort->findByUuid($event->getJobId());
        if ($job === null) {
            $this->logger->warning('LearningEngine: job not found', [
                'jobId' => $event->getJobId()->toString(),
            ]);
            return;
        }

        $probeData = $job->getProbeData();
        $sourceHeight = (int)($probeData['videoStreams'][0]['height'] ?? 0);
        $sourceCodec = (string)($probeData['videoStreams'][0]['codecName'] ?? '');
        $tier = QualityTier::fromString($job->getQualityTierName());

        // Get current utilization at completion time (null-safe)
        $utilization = $this->sampler->getLatest() ?? [];
        $cpuPercent = (float)($utilization['cpu_percent'] ?? 0.0);
        $gpuPercent = (float)($utilization['gpu_percent'] ?? 0.0);

        $sample = new UtilizationSample(
            cpuPercent: $cpuPercent,
            gpuPercent: $gpuPercent,
            encodeFps: 0.0, // Not available from completion event
            sourceHeight: $sourceHeight,
            sourceCodec: $sourceCodec,
            hardwareAccelerated: $this->prober->getProfile()->isHardware(),
            targetBitrate: $tier->videoBitrate,
            qualityTier: $tier->name,
            activeStreams: $this->governor->getActiveStreamCount(),
        );

        $this->governor->recordSample($sample);

        // Release the stream from active set
        $this->governor->releaseStream($event->getJobId());

        $this->logger->info('LearningEngine: recorded utilization sample', [
            'jobId' => $event->getJobId()->toString(),
            'tier' => $tier->name,
            'cpu' => $cpuPercent,
            'gpu' => $gpuPercent,
            'totalSamples' => $this->governor->getModel()->sampleCount(),
            'governorState' => $this->governor->getState()->value,
        ]);

        // Persist if threshold reached
        if ($this->persister->shouldPersist()) {
            $this->persister->persist();
        }
    }
}
