<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Mid-stream capacity guard published by QoL.
 *
 * Returns silently while dispatch is within the CPU budget. Over budget it
 * throws QoL's stream-budget exhaustion exception, which the HTTP layer maps to 503.
 */
interface BudgetGuardInterface
{
    /**
     * Guard that the given job's encoding dispatch is within capacity budget.
     *
     * @throws \RuntimeException if over budget
     */
    public function guardDispatch(Uuid $jobId): void;
}
