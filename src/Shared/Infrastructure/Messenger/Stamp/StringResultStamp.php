<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class StringResultStamp implements StampInterface
{
    public function __construct(
        private string $result,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return is_string($result) ? new self($result) : null;
    }

    public function getResult(): string
    {
        return $this->result;
    }
}
