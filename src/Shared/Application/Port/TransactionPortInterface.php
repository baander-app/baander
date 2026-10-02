<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface TransactionPortInterface
{
    /**
     * Commit database mutations and event capture as one operation.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
