<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

/**
 * Port interface for QoL admin operations.
 * Thin wrapper around StreamGovernor for Interface→Application layering.
 */
interface QoLAdminPortInterface
{
    /**
     * @return array{state: string, profile: string, active_streams: int, sample_count: int, model_ready: bool, budget_cap: float}
     */
    public function getStatus(): array;

    /**
     * @return list<array{job_id: string, quality_tier: string, predicted_cost: float}>
     */
    public function getActiveStreams(): array;

    public function setProfile(string $profile): string;

    public function resetLearning(): string;
}
