<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface OAuthTokenInvalidatorInterface
{
    /**
     * Delete existing OAuth grants and their metadata, then invalidate token cache.
     * All issuers and resource workers must remain stopped/drained until completion
     * and configuration activation. Retry uncertain outcomes only while offline;
     * repeating after workers resume can delete newly issued grants.
     *
     * @return int Committed deleted rows, including grant metadata.
     * @throws \App\Auth\Application\Exception\OAuthTokenCacheInvalidationFailed Database deletion committed; cache invalidation needs retry.
     * @throws \RuntimeException Database outcome is unconfirmed.
     */
    public function invalidate(): int;
}
