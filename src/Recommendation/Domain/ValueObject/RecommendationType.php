<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Represents a valid recommendation entity type.
 *
 * Allows polymorphic combinations (e.g., song→album, artist→artist)
 * while catching typos at the boundary.
 */
final readonly class RecommendationType implements Stringable, JsonSerializable
{
    private const ALLOWED = [
        'song',
        'album',
        'artist',
        'movie',
        'video',
    ];

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower($value);

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid recommendation type "%s". Allowed types: %s.',
                $value,
                implode(', ', self::ALLOWED),
            ));
        }

        return new self($normalized);
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
