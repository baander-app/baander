<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Backs stream admission and completion with the in-memory StreamGovernor.
 *
 * Admission performs no I/O, so the synchronous veto never blocks the HTTP
 * worker. Completion records a learning sample, releases the allocation and
 * persists learning state when the persister's threshold is reached.
 */
final class StreamAdmissionService implements StreamAdmissionPortInterface
{
    private const float DEFAULT_PREDICTED_COST = 25.0;

    public function __construct(
        private readonly StreamGovernor        $governor,
        private readonly CpuGpuSampler         $sampler,
        private readonly LearningDataPersister $persister,
        private readonly LoggerInterface       $logger,
    )
    {
    }

    public function admit(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        bool   $hardwareAccelerated,
    ): void
    {
        // May throw StreamBudgetExhausted (veto)
        $allowedTier = $this->governor->evaluateBudget(
            sourceHeight: $sourceHeight,
            targetBitrate: $targetBitrate,
            hardwareAccelerated: $hardwareAccelerated,
            requestedTier: $qualityTier,
        );

        $model = $this->governor->getModel();
        $predictedCost = $model->predict($sourceHeight, $targetBitrate, $hardwareAccelerated)
            ?? $model->averageCostForTier($qualityTier)
            ?? self::DEFAULT_PREDICTED_COST;

        $this->governor->allocateStream($jobId, $allowedTier, $predictedCost);

        $this->logger->info('StreamAdmission: allocated stream', [
            'jobId' => $jobId->toString(),
            'requestedTier' => $qualityTier,
            'allowedTier' => $allowedTier,
            'predictedCost' => $predictedCost,
            'hardwareAccelerated' => $hardwareAccelerated,
        ]);
    }

    public function complete(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        string $sourceCodec,
        bool   $hardwareAccelerated,
    ): void
    {
        $utilization = $this->sampler->getLatest() ?? [];
        $cpuPercent = (float)($utilization['cpu_percent'] ?? 0.0);
        $gpuPercent = (float)($utilization['gpu_percent'] ?? 0.0);

        $this->governor->recordSample(new UtilizationSample(
            cpuPercent: $cpuPercent,
            gpuPercent: $gpuPercent,
            encodeFps: 0.0, // Not available at completion
            sourceHeight: $sourceHeight,
            sourceCodec: $sourceCodec,
            hardwareAccelerated: $hardwareAccelerated,
            targetBitrate: $targetBitrate,
            qualityTier: $qualityTier,
            activeStreams: $this->governor->getActiveStreamCount(),
        ));

        $this->governor->releaseStream($jobId);

        $this->logger->info('StreamAdmission: recorded utilization sample', [
            'jobId' => $jobId->toString(),
            'tier' => $qualityTier,
            'cpu' => $cpuPercent,
            'gpu' => $gpuPercent,
            'totalSamples' => $this->governor->getModel()->sampleCount(),
            'governorState' => $this->governor->getState()->value,
        ]);

        if ($this->persister->shouldPersist()) {
            $this->persister->persist();
        }
    }
}
