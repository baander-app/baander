<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Capacity check guard for mid-stream encoding dispatch.
 *
 * Implemented by the QoL bounded context's BudgetGuard service.
 * Returns silently if dispatch is within budget; throws on over-budget.
 */
interface BudgetGuardInterface
{
    /**
     * Guard that the given job's encoding dispatch is within capacity budget.
     *
     * @throws \App\QoL\Domain\Exception\StreamBudgetExhausted if over budget
     */
    public function guardDispatch(Uuid $jobId): void;
}
