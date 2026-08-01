<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

final class InvalidJobTransitionException extends \RuntimeException
{
    public static function fromStatus(string $currentStatus, string $targetStatus): self
    {
        return new self(sprintf('Cannot transition job from "%s" to "%s".', $currentStatus, $targetStatus));
    }
}
