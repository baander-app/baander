<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class IntResultStamp implements StampInterface
{
    public function __construct(
        private int $result,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return is_int($result) ? new self($result) : null;
    }

    public function getResult(): int
    {
        return $this->result;
    }
}
