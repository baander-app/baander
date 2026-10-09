<?php

declare(strict_types=1);

namespace App\Shared\Interface\Attribute;

/**
 * Records why an admin route has no console counterpart.
 *
 * The reason names the lasting decision behind the exemption.
 * `tests/Integration/AdminCliParityTest.php` fails when the reason defers the
 * command, or when the attribute stays on a method that no route reaches.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class CliParityExemption
{
    public function __construct(
        public string $reason,
    ) {
    }
}
