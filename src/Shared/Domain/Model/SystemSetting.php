<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

use DateTimeImmutable;

final class SystemSetting
{
    public function __construct(
        private string $key,
        private mixed $value,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(mixed $value): void
    {
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }
}
