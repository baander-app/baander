<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/** Test-only message whose handler fails on demand, for failure transport tests. */
final readonly class FailureTransportProbe
{
    public function __construct(
        public string $label,
        public bool $fail,
    ) {
    }
}
