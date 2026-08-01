<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

/**
 * Centralized tag name constants for Symfony's TagAwareCacheInterface.
 *
 * Only tags with active consumers should be defined here.
 * Per-context constants (CATALOG_*, PLAYLIST_*, etc.) are deferred
 * until actual cache consumers exist.
 */
final readonly class CacheTags
{
    /** Prefix for all OAuth token revocation cache tags. */
    public const OAUTH_TOKEN = 'oauth_token';

    /**
     * Per-token tag for targeted invalidation.
     * Usage: CacheTags::OAUTH_TOKEN . '_' . $tokenId
     */
    public static function oauthToken(string $tokenId): string
    {
        return self::OAUTH_TOKEN . '_' . $tokenId;
    }

    private function __construct()
    {
    }
}
