<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use InvalidArgumentException;
use Stringable;

/**
 * Value object representing a MusicBrainz entity identifier.
 *
 * MusicBrainz IDs are UUIDs. An empty value is allowed to represent
 * a nullable/missing identifier (e.g. a release not yet linked to
 * MusicBrainz).
 */
final readonly class MusicbrainzId implements Stringable
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private string $id;

    private function __construct(string $id)
    {
        $trimmed = trim($id);

        if ($trimmed === '') {
            $this->id = '';

            return;
        }

        if (strlen($trimmed) !== 36) {
            throw new InvalidArgumentException(sprintf(
                'MusicBrainz ID must be a 36-character UUID, got %d characters.',
                strlen($trimmed),
            ));
        }

        if (preg_match(self::UUID_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'MusicBrainz ID "%s" is not a valid UUID format.',
                $trimmed,
            ));
        }

        $this->id = strtolower($trimmed);
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
