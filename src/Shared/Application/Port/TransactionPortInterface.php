<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\Exception\TransactionDeadlockException;

interface TransactionPortInterface
{
    /**
     * Commit database mutations and event capture as one operation.
     *
     * When the operation fails, nothing it wrote is committed and its error is rethrown as it was,
     * except for a deadlock: when the database ends the transaction to break a deadlock with another
     * writer, or because it could not serialize it with one, the port throws
     * TransactionDeadlockException with the database's error as the previous exception, so a caller
     * can tell a retryable deadlock from other failures without knowing the database layer.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     *
     * @throws TransactionDeadlockException when the database ended this transaction to break a deadlock or a serialization conflict
     */
    public function run(callable $operation): mixed;
}
