<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use InvalidArgumentException;
use Stringable;

/**
 * Value object representing a Discogs entity identifier.
 *
 * Discogs IDs are numeric strings. An empty value is allowed to represent
 * a nullable/missing identifier (e.g. a release not yet linked to Discogs).
 */
final readonly class DiscogsId implements Stringable
{
    private const NUMERIC_PATTERN = '/^[1-9][0-9]*$/';

    private string $id;

    private function __construct(string $id)
    {
        $trimmed = trim($id);

        if ($trimmed === '') {
            $this->id = '';

            return;
        }

        if (preg_match(self::NUMERIC_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Discogs ID must be a numeric string, got "%s".',
                $trimmed,
            ));
        }

        $this->id = $trimmed;
    }

    public static function fromString(?string $id): ?self
    {
        if ($id === null) {
            return null;
        }

        return new self($id);
    }

    public function toString(): string
    {
        return $this->id;
    }

    public function isEmpty(): bool
    {
        return $this->id === '';
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
