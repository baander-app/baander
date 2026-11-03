<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Transcode;

use App\QoL\Application\Port\BudgetGuardInterface;
use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\StreamGovernor;
use App\Shared\Domain\Model\Uuid;

/**
 * Implements the mid-stream capacity guard.
 * Checks the governed streams' observed load, each stream's measured encoder cost or
 * its prediction until it has one, against the profile's budget cap.
 */
final class BudgetGuard implements BudgetGuardInterface
{
    public function __construct(
        private readonly StreamGovernor $governor,
    )
    {
    }

    public function guardDispatch(Uuid $jobId): void
    {
        if ($this->governor->getState() === GovernorState::Learning) {
            return; // During learning, never gate
        }

        $load = $this->governor->observedLoad();
        $budgetCap = $this->governor->getProfile()->budgetCap() * 100.0;

        if ($load > $budgetCap) {
            throw new StreamBudgetExhausted(
                activeStreams: $this->governor->getActiveStreamCount(),
                budgetUsed: $load / 100.0,
                requestedTier: 'mid_stream_guard',
            );
        }
    }
}
