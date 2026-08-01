<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

final readonly class Uuid implements Stringable, JsonSerializable
{
    private string $value;

    public function __construct(?string $value = null)
    {
        if ($value === null) {
            // UUID v7: time-sortable, similar properties to ULID
            $this->value = SymfonyUuid::v7()->toRfc4122();

            return;
        }

        if (!SymfonyUuid::isValid($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid UUID.', $value));
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function generate(): self
    {
        return new self();
    }

    public static function v4(): self
    {
        return new self(SymfonyUuid::v4()->toRfc4122());
    }

    public static function v7(): self
    {
        return new self(SymfonyUuid::v7()->toRfc4122());
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function toBinary(): string
    {
        return SymfonyUuid::fromRfc4122($this->value)->toBinary();
    }

    public function toDateTime(): \DateTimeImmutable
    {
        return SymfonyUuid::fromRfc4122($this->value)->getDateTime();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
