<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;

final class UnknownRateLimiterException extends RuntimeException
{
    /** @param list<string> $validNames */
    public static function named(string $name, array $validNames): self
    {
        return new self(sprintf('Unknown rate limiter "%s". Valid names: %s', $name, implode(', ', $validNames)));
    }
}
