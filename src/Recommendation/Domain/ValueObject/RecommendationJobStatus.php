<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\ValueObject;

enum RecommendationJobStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
