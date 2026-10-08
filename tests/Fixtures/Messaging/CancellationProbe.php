<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/** Test-only job that handles a number of items with a cancellation checkpoint before each. */
final readonly class CancellationProbe
{
    public function __construct(
        public string $label,
        public int $items,
    ) {
    }
}
