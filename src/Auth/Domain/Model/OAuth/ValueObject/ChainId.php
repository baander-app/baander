<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use App\Shared\Domain\Model\Uuid;
use JsonSerializable;
use Stringable;

/**
 * Value object representing a token chain family.
 *
 * A ChainId groups all tokens produced by successive refresh operations
 * into a single lineage. If a replay attack is detected on any refresh
 * token, the entire chain can be revoked.
 */
final readonly class ChainId implements Stringable, JsonSerializable
{
    private Uuid $uuid;

    public function __construct(?Uuid $uuid = null)
    {
        $this->uuid = $uuid ?? Uuid::v4();
    }

    public static function generate(): self
    {
        return new self();
    }

    public static function fromUuid(Uuid $uuid): self
    {
        return new self($uuid);
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function toString(): string
    {
        return $this->uuid->toString();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function __toString(): string
    {
        return $this->uuid->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->uuid->toString();
    }
}
