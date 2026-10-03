<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

final class OAuthTokenCacheInvalidationFailed extends RuntimeException
{
    public function __construct(public readonly int $deletedRows)
    {
        parent::__construct('OAuth token deletion committed, but cache invalidation was not confirmed.');
    }
}
