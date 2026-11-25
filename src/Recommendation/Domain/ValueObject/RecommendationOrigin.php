<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\ValueObject;

/**
 * Who wrote a recommendation. The per-user row cap counts the rows a member wrote; the
 * generator replaces its own rows on every run and is not capped.
 */
enum RecommendationOrigin: string
{
    case Generator = 'generator';
    case Member = 'member';
}
