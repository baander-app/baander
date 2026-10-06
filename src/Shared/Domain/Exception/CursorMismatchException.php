<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A pagination cursor was issued for a different ordering than the request asks for.
 *
 * Keyset positions are only meaningful in the ordering that produced them, so
 * continuing with another sort field or direction would skip or repeat rows.
 */
final class CursorMismatchException extends \InvalidArgumentException
{
    public static function forBinding(string $expected): self
    {
        return new self(sprintf('The cursor does not belong to the requested ordering "%s".', $expected));
    }
}
