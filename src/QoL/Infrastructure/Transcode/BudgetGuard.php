<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Transcode;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\BudgetGuardInterface;

/**
 * Implements the mid-stream capacity guard.
 * Checks real-time CPU utilization against budget cap before each segment dispatch.
 */
final class BudgetGuard implements BudgetGuardInterface
{
    public function __construct(
        private readonly StreamGovernor $governor,
        private readonly CpuGpuSampler  $sampler,
    )
    {
    }

    public function guardDispatch(Uuid $jobId): void
    {
        if ($this->governor->getState() === GovernorState::Learning) {
            return; // During learning, never gate
        }

        $utilization = $this->sampler->getLatest() ?? [];
        $cpuPercent = (float)($utilization['cpu_percent'] ?? 0.0);
        $budgetCap = $this->governor->getProfile()->budgetCap() * 100.0;

        if ($cpuPercent > $budgetCap) {
            throw new StreamBudgetExhausted(
                activeStreams: $this->governor->getActiveStreamCount(),
                budgetUsed: $cpuPercent / 100.0,
                requestedTier: 'mid_stream_guard',
            );
        }
    }
}
