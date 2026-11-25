<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Exception;

use App\Recommendation\Application\RecommendationLimits;
use App\Shared\Application\Exception\ConflictException;

/** A member's recommendation cannot be stored in the current state; nothing was written. */
final class RecommendationConflictException extends ConflictException
{
    public static function limitReached(): self
    {
        return new self(
            sprintf('A user may keep at most %d recommendations written through the API.', RecommendationLimits::MEMBER_ROWS_PER_USER),
            ['reason' => 'recommendation_limit', 'limit' => RecommendationLimits::MEMBER_ROWS_PER_USER],
        );
    }

    public static function alreadyStored(): self
    {
        return new self(
            'A recommendation with this source, target and name is already stored.',
            ['reason' => 'recommendation_exists'],
        );
    }
}
