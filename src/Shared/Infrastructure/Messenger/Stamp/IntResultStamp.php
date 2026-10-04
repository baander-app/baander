<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

final readonly class IntResultStamp implements ResultStampInterface
{
    public function __construct(
        private int $result,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return is_int($result) ? new self($result) : null;
    }

    public function getResult(): int
    {
        return $this->result;
    }
}
