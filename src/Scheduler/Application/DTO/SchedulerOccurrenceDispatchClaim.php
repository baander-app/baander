<?php

declare(strict_types=1);

namespace App\Scheduler\Application\DTO;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Delivery reservation ownership; never execution authority. */
#[Exclude]
final readonly class SchedulerOccurrenceDispatchClaim
{
    public function __construct(public Uuid $occurrenceId, public Uuid $token)
    {
    }
}
