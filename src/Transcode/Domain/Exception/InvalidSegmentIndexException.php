<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

final class InvalidSegmentIndexException extends \InvalidArgumentException
{
    public static function negativeIndex(): self
    {
        return new self('Segment index must be non-negative.');
    }
}
