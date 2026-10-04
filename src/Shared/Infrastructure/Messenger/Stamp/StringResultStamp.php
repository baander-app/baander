<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

final readonly class StringResultStamp implements ResultStampInterface
{
    public function __construct(
        private string $result,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return is_string($result) ? new self($result) : null;
    }

    public function getResult(): string
    {
        return $this->result;
    }
}
