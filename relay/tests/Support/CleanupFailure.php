<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class CleanupFailure extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(
            'Resource cleanup failed: ' . implode('; ', array_map(static fn(\Throwable $failure): string => $failure->getMessage(), $failures)),
            previous: $failures[0],
        );
    }
}
