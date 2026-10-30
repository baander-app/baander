<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

final readonly class CleanupReport
{
    /** @param list<string> $errors */
    public function __construct(public array $errors, public string $receipt) {}

    public function successful(): bool
    {
        return $this->errors === [];
    }

    public function message(): string
    {
        return 'Resource cleanup failed: ' . implode('; ', $this->errors) . '. Retry receipt: ' . $this->receipt;
    }
}
