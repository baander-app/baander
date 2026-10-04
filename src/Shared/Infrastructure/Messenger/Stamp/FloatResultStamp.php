<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

final readonly class FloatResultStamp implements ResultStampInterface
{
    public function __construct(
        private float $result,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return is_float($result) ? new self($result) : null;
    }

    public function getResult(): float
    {
        return $this->result;
    }
}
