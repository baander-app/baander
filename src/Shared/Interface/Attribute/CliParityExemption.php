<?php

declare(strict_types=1);

namespace App\Shared\Interface\Attribute;

/**
 * Records why an admin route has no console counterpart.
 *
 * The reason names the decision behind the exemption, such as the follow-up plan
 * that adds the command. `tests/Integration/AdminCliParityTest.php` fails when
 * the attribute stays on a method that no route reaches.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class CliParityExemption
{
    public function __construct(
        public string $reason,
    ) {
    }
}
