<?php

declare(strict_types=1);

namespace App\Recommendation\Application;

use App\Recommendation\Domain\ValueObject\RecommendationType;

/**
 * The bounds on the recommendations a member writes through the API. The request class
 * validates the values, CreateRecommendationHandler enforces the count, and the database checks
 * the stored name length (chk_recommendations_name_length). A row stored before a bound existed
 * stays readable.
 */
final class RecommendationLimits
{
    /** The entity types a source and a target may have. */
    public const array ENTITY_TYPES = RecommendationType::ALLOWED;

    public const int NAME_LENGTH = 255;

    /** The range every strategy of the generator scores in. */
    public const float MIN_SCORE = 0.0;

    public const float MAX_SCORE = 1.0;

    /** The range of the integer `position` column. */
    public const int MIN_POSITION = -2_147_483_648;

    public const int MAX_POSITION = 2_147_483_647;

    /** Rows a user may keep that were written through the API; the generator's rows do not count. */
    public const int MEMBER_ROWS_PER_USER = 1000;
}
