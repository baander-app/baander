<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The DPoP nonce or jti store did not answer, so no proof can be judged.
 *
 * Only a failure to reach the store raises it: a refused or dropped connection, a read
 * that timed out, or an exhausted connection pool. A nonce that is missing and a jti
 * that was seen before are verdicts and keep their boolean answers. The endpoints
 * answer this exception with 503 and `Retry-After`; the checks stay fail-closed, since
 * no proof is accepted without the replay check.
 */
final class DpopStoreUnavailableException extends RuntimeException
{
    public static function nonceStore(Throwable $cause): self
    {
        return new self('The DPoP nonce store is unavailable.', previous: $cause);
    }

    public static function jtiStore(Throwable $cause): self
    {
        return new self('The DPoP jti store is unavailable.', previous: $cause);
    }
}
