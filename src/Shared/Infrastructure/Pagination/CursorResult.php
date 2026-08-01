<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Model\Cursor;

/**
 * Internal infrastructure DTO representing a cursor-paginated result set.
 *
 * Holds raw Cursor objects (not encoded strings). Convert to CursorPage for
 * domain/application use via CursorCodec encoding.
 *
 * @internal
 *
 * @param array<mixed> $items
 */
final readonly class CursorResult
{
    public function __construct(
        public readonly array $items,
        public readonly ?Cursor $nextCursor,
        public readonly ?Cursor $prevCursor,
        public readonly bool $hasNextPage,
        public readonly bool $hasPreviousPage,
        public readonly int $total,
        public readonly bool $staleCursor,
        public readonly int $perPage,
    ) {
    }
}
