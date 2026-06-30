<?php

declare(strict_types=1);

namespace App\QoL\Domain\Service;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\StreamAllocation;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Service\QualityLadder;
use App\Transcode\Domain\ValueObject\QualityTier;

/**
 * Core domain service for adaptive stream governance.
 *
 * Owns budget evaluation, tier selection, active stream tracking,
 * and learning model coordination. All state is in-memory; persistence
 * is handled by infrastructure subscribers.
 */
final class StreamGovernor
{
    private GovernorState $state = GovernorState::Learning;
    private AlgorithmProfile $profile = AlgorithmProfile::Balanced;

    /** @var array<string, StreamAllocation> jobId.toString() → allocation */
    private array $activeStreams = [];

    public function __construct(
        private readonly LearningModel $model,
    )
    {
    }

    /**
     * Evaluate whether a stream at the requested tier fits the budget.
     *
     * During Learning state: always allow, never reject.
     * During Active state: predict cost, compare against remaining budget,
     * downgrade tier if needed, or reject with StreamBudgetExhausted.
     *
     * @return string The allowed quality tier name (may be lower than requested)
     *
     * @throws StreamBudgetExhausted if no tier fits the budget
     */
    public function evaluateBudget(
        int    $sourceHeight,
        int    $targetBitrate,
        bool   $hardwareAccelerated,
        string $requestedTier,
    ): string
    {
        // During learning, allow everything
        if ($this->state === GovernorState::Learning) {
            return $requestedTier;
        }

        $predictedCost = $this->model->predict($sourceHeight, $targetBitrate, $hardwareAccelerated);

        // Fallback: if model returns null (shouldn't happen in Active state), use per-tier average
        if ($predictedCost === null) {
            $predictedCost = $this->model->averageCostForTier($requestedTier) ?? 50.0;
        }

        $budgetCap = $this->profile->budgetCap() * 100.0; // e.g., 80.0
        $usedBudget = $this->calculateUsedBudget();
        $remainingBudget = $budgetCap - $usedBudget;

        // Requested tier fits
        if ($predictedCost <= $remainingBudget) {
            return $requestedTier;
        }

        // Walk down tiers to find the highest that fits
        $tiers = QualityLadder::defaultTiers(); // ascending: 360p → 4K
        $tierNames = array_map(static fn(QualityTier $t): string => $t->name, $tiers);
        $requestedIdx = array_search($requestedTier, $tierNames, true);

        if ($requestedIdx === false) {
            $requestedIdx = count($tiers) - 1;
        }

        for ($i = $requestedIdx - 1; $i >= 0; $i--) {
            $tier = $tiers[$i];
            // Predict cost of encoding this SOURCE at the lower tier's bitrate
            $costAtTier = $this->model->predict($sourceHeight, $tier->videoBitrate, $hardwareAccelerated);

            if ($costAtTier === null) {
                $costAtTier = $this->model->averageCostForTier($tier->name) ?? $predictedCost * ($tier->videoBitrate / max($targetBitrate, 1));
            }

            if ($costAtTier <= $remainingBudget) {
                return $tier->name;
            }
        }

        // No tier fits — reject
        throw new StreamBudgetExhausted(
            activeStreams: count($this->activeStreams),
            budgetUsed: $usedBudget / 100.0,
            requestedTier: $requestedTier,
        );
    }

    /**
     * Sum of predicted costs for all active streams.
     */
    private function calculateUsedBudget(): float
    {
        return array_sum(array_map(
            static fn(StreamAllocation $a): float => $a->predictedCost,
            $this->activeStreams,
        ));
    }

    /**
     * Allocate a stream to the active set.
     */
    public function allocateStream(Uuid $jobId, string $qualityTier, float $predictedCost): void
    {
        $this->activeStreams[$jobId->toString()] = new StreamAllocation(
            jobId: $jobId,
            qualityTier: $qualityTier,
            predictedCost: $predictedCost,
        );

        // Transition to Active if model is ready and currently Learning
        if ($this->state === GovernorState::Learning && $this->model->isReady()) {
            $this->state = GovernorState::Active;
        }
    }

    /**
     * Release a stream from the active set.
     */
    public function releaseStream(Uuid $jobId): void
    {
        unset($this->activeStreams[$jobId->toString()]);
    }

    /**
     * Get the allowed tiers for manifest filtering.
     *
     * Uses per-tier average cost from training data to determine which tiers
     * fit within the per-stream budget allocation. Source-agnostic — returns
     * tiers that are safe for any source.
     *
     * @return list<string> Tier names that fit within the budget
     */
    public function getAllowedTiers(): array
    {
        if ($this->state === GovernorState::Learning) {
            // During learning, all tiers are allowed
            return array_map(
                static fn(QualityTier $t): string => $t->name,
                QualityLadder::defaultTiers(),
            );
        }

        $budgetCap = $this->profile->budgetCap() * 100.0;
        $usedBudget = $this->calculateUsedBudget();
        $remainingBudget = $budgetCap - $usedBudget;

        // Per-stream budget: remaining / (active + 1 potential new stream)
        $perStreamBudget = $remainingBudget / max(count($this->activeStreams) + 1, 1);

        $allowed = [];
        $tiers = QualityLadder::defaultTiers();

        foreach ($tiers as $tier) {
            $avgCost = $this->model->averageCostForTier($tier->name);

            if ($avgCost !== null && $avgCost <= $perStreamBudget) {
                $allowed[] = $tier->name;
            } else if ($avgCost === null) {
                // No data for this tier — include it (permissive)
                $allowed[] = $tier->name;
            }
        }

        // Always include at least 360p as floor
        if ($allowed === []) {
            return ['360p'];
        }

        return $allowed;
    }

    /**
     * Record a completed stream's utilization sample for learning.
     */
    public function recordSample(UtilizationSample $sample): void
    {
        $this->model->addSample($sample);
    }

    // --- Getters and state management ---

    public function getProfile(): AlgorithmProfile
    {
        return $this->profile;
    }

    public function setProfile(AlgorithmProfile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @return list<StreamAllocation>
     */
    public function getActiveStreams(): array
    {
        return array_values($this->activeStreams);
    }

    public function getActiveStreamCount(): int
    {
        return count($this->activeStreams);
    }

    public function getModel(): LearningModel
    {
        return $this->model;
    }

    /**
     * Reset learning data and return to Learning state.
     */
    public function resetLearning(): void
    {
        $this->model->reset();
        $this->state = GovernorState::Learning;
        $this->activeStreams = [];
    }

    /**
     * Transition to Active state (called after hardware check or state reload).
     */
    public function activate(): void
    {
        if ($this->model->isReady()) {
            $this->state = GovernorState::Active;
        }
    }

    /**
     * Serialize full governor state for persistence.
     */
    public function exportState(): array
    {
        return [
            'state' => $this->state->value,
            'profile' => $this->profile->value,
            'active_streams' => array_map(
                static fn(StreamAllocation $a): array => $a->jsonSerialize(),
                $this->activeStreams,
            ),
            'model' => $this->model->getState(),
        ];
    }

    public function getState(): GovernorState
    {
        return $this->state;
    }

    /**
     * Restore governor state from persistence.
     */
    public function importState(array $state): void
    {
        $this->state = GovernorState::from($state['state'] ?? 'learning');
        $this->profile = AlgorithmProfile::from($state['profile'] ?? 'balanced');

        $this->activeStreams = [];
        foreach ($state['active_streams'] ?? [] as $streamData) {
            $allocation = StreamAllocation::fromArray($streamData);
            $this->activeStreams[$allocation->jobId->toString()] = $allocation;
        }

        if (isset($state['model'])) {
            $this->model->restoreState($state['model']);
        }
    }
}
