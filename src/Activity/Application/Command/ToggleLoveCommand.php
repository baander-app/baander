<?php

declare(strict_types=1);

namespace App\Activity\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Command DTO for toggling the love flag on a media activity.
 */
final readonly class ToggleLoveCommand
{
    public function __construct(
        private Uuid $activityId,
    ) {
    }

    public function getActivityId(): Uuid
    {
        return $this->activityId;
    }
}
