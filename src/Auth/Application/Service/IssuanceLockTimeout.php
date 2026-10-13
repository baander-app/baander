<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Bounds the lock waits of a token-issuing transaction.
 *
 * Issuance and refresh lock the client row, the presented refresh row, its successors
 * and the access rows in one order, so they never deadlock; what remains is a lock a
 * stuck transaction holds. Past this bound the issuing transaction fails as a server
 * fault, which the token endpoints answer with 503 and Retry-After inside the client's
 * attempt timeout, instead of holding the request until the client gives up.
 *
 * The setting is transaction-local: it applies only to the transaction that set it.
 */
final class IssuanceLockTimeout
{
    /** Well inside the web client's 10 s attempt timeout, with room for the Redis reads before it. */
    public const int MILLISECONDS = 3000;

    public static function apply(EntityManagerInterface $entityManager): void
    {
        $entityManager->getConnection()->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", self::MILLISECONDS));
    }
}
