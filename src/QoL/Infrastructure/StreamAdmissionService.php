<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\EncoderWork;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Backs stream admission and completion with the in-memory StreamGovernor.
 *
 * Admission performs no I/O, so the synchronous veto never blocks the
 * video child. Every admission decision, allocation or veto, is logged with the
 * governor state it was made in. Observation turns the stream's encoder work into its measured cost.
 * Completion records that cost as a learning sample, releases the allocation and
 * persists learning state when the persister's threshold is reached.
 */
final class StreamAdmissionService implements StreamAdmissionPortInterface
{
    private const float DEFAULT_PREDICTED_COST = 25.0;

    public function __construct(
        private readonly StreamGovernor        $governor,
        private readonly LearningDataPersister $persister,
        private readonly LoggerInterface       $logger,
        private readonly HostCpus              $hostCpus,
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
        // The governor's state decides whether the budget applies, so every decision records it.
        $governorState = $this->governor->getState()->value;
        try {
            $allowedTier = $this->governor->evaluateBudget(
                sourceHeight: $sourceHeight,
                targetBitrate: $targetBitrate,
                hardwareAccelerated: $hardwareAccelerated,
                requestedTier: $qualityTier,
            );
        } catch (StreamBudgetExhausted $veto) {
            $this->logger->warning('StreamAdmission: vetoed stream', [
                'jobId' => $jobId->toString(),
                'requestedTier' => $qualityTier,
                'hardwareAccelerated' => $hardwareAccelerated,
                'governorState' => $governorState,
                'learningSamples' => $this->governor->getModel()->sampleCount(),
            ]);
            throw $veto;
        }

        $model = $this->governor->getModel();
        $predictedCost = $model->predict($sourceHeight, $targetBitrate, $hardwareAccelerated)
            ?? $model->averageCostForTier($qualityTier, $hardwareAccelerated)
            ?? self::DEFAULT_PREDICTED_COST;

        $this->governor->allocateStream($jobId, $allowedTier, $predictedCost);

        $this->logger->info('StreamAdmission: allocated stream', [
            'jobId' => $jobId->toString(),
            'requestedTier' => $qualityTier,
            'allowedTier' => $allowedTier,
            'predictedCost' => $predictedCost,
            'hardwareAccelerated' => $hardwareAccelerated,
            'governorState' => $governorState,
            'learningSamples' => $model->sampleCount(),
        ]);
    }

    public function observe(Uuid $jobId, EncoderWork $work): void
    {
        $cost = $work->costPercent($this->hostCpus->count());
        if ($cost !== null) {
            $this->governor->recordStreamCost($jobId, $cost);
        }
    }

    public function complete(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        string $sourceCodec,
        bool   $hardwareAccelerated,
        EncoderWork $work,
    ): void
    {
        $cost = $work->costPercent($this->hostCpus->count());
        if ($cost === null) {
            $this->governor->releaseStream($jobId);
            $this->logger->info('StreamAdmission: too little encoder output; allocation released without learning', [
                'jobId' => $jobId->toString(),
                'outputSeconds' => $work->outputSeconds,
            ]);

            return;
        }

        $this->governor->recordSample(new UtilizationSample(
            cpuPercent: $cost,
            encodeFps: 0.0, // Not available at completion
            sourceHeight: $sourceHeight,
            sourceCodec: $sourceCodec,
            hardwareAccelerated: $hardwareAccelerated,
            targetBitrate: $targetBitrate,
            qualityTier: $qualityTier,
            activeStreams: $this->governor->getActiveStreamCount(),
        ));

        $this->governor->releaseStream($jobId);

        $this->logger->info('StreamAdmission: recorded encoder cost sample', [
            'jobId' => $jobId->toString(),
            'tier' => $qualityTier,
            'cost' => $cost,
            'encoderCpuSeconds' => $work->cpuSeconds,
            'outputSeconds' => $work->outputSeconds,
            'hardwareAccelerated' => $hardwareAccelerated,
            'totalSamples' => $this->governor->getModel()->sampleCount(),
            'governorState' => $this->governor->getState()->value,
        ]);

        if ($this->persister->shouldPersist()) {
            $this->persister->persist();
        }
    }
}
