<?php

declare(strict_types=1);

namespace App\Shared\Interface\Attribute;

/**
 * Names the console command that performs the same action as this admin route.
 *
 * Every admin-guarded route carries this attribute or {@see CliParityExemption}.
 * The named command must exist and, unless a framework bundle provides it, have
 * an operator docs page. `tests/Integration/AdminCliParityTest.php` enforces this.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class CliCounterpart
{
    public function __construct(
        public string $command,
    ) {
    }
}
