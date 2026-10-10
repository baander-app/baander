<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Shared\Application\Port\TransactionPortInterface;

/** Runs each operation as it is, for unit tests whose fake ports keep their own state; counts the runs. */
final class PassThroughTransaction implements TransactionPortInterface
{
    public int $runs = 0;
    /** Whether an operation is running, for fakes that must be called inside the transaction. */
    public bool $active = false;

    public function run(callable $operation): mixed
    {
        $this->runs++;
        $this->active = true;
        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}
