<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ExecuteRecommendationJobCommand
{
    public function __construct(public Uuid $jobId)
    {
    }
}
