<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class FloatResultStamp implements StampInterface
{
    public function __construct(
        private float $result,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return is_float($result) ? new self($result) : null;
    }

    public function getResult(): float
    {
        return $this->result;
    }
}
