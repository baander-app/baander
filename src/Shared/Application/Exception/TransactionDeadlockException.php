<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The database ended a transaction to break a deadlock with another writer, or because it could
 * not serialize the transaction with one, and rolled back everything it wrote. Nothing the
 * operation decided was wrong: running it again may succeed.
 *
 * The transaction port throws it, keeping the database's error as the previous exception. A
 * Messenger handler can let it fail the message, which then retries as any failure does; a use
 * case an admin started can answer with a conflict worth retrying. Left uncaught, HTTP reports it
 * as an internal error, as it did the database's error before.
 */
final class TransactionDeadlockException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The transaction deadlocked with another writer and was rolled back.', 0, $previous);
    }
}
