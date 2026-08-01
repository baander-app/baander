<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

final class InvalidSessionTransitionException extends \RuntimeException
{
    public static function fromState(string $currentState, string $targetState): self
    {
        return new self(sprintf('Cannot transition session from "%s" to "%s".', $currentState, $targetState));
    }
}
