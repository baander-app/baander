<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;

/**
 * Infrastructure implementation of QoLAdminPortInterface.
 * Thin wrapper delegating to the in-memory StreamGovernor singleton.
 */
final class QoLAdminService implements QoLAdminPortInterface
{
    public function __construct(
        private readonly StreamGovernor $governor,
    )
    {
    }

    public function getStatus(): array
    {
        $model = $this->governor->getModel();

        return [
            'state' => $this->governor->getState()->value,
            'profile' => $this->governor->getProfile()->value,
            'active_streams' => $this->governor->getActiveStreamCount(),
            'sample_count' => $model->sampleCount(),
            'model_ready' => $model->isReady(),
            'budget_cap' => $this->governor->getProfile()->budgetCap(),
        ];
    }

    public function getActiveStreams(): array
    {
        return array_map(
            static fn($s): array => [
                'job_id' => $s->jobId->toString(),
                'quality_tier' => $s->qualityTier,
                'predicted_cost' => round($s->predictedCost, 2),
            ],
            $this->governor->getActiveStreams(),
        );
    }

    public function setProfile(string $profile): string
    {
        $algorithmProfile = AlgorithmProfile::from($profile);
        $this->governor->setProfile($algorithmProfile);

        return $algorithmProfile->value;
    }

    public function resetLearning(): string
    {
        $this->governor->resetLearning();

        return GovernorState::Learning->value;
    }
}
