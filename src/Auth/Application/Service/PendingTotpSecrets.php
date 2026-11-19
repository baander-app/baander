<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Shared\Domain\Model\Uuid;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The secret a TOTP setup generated, held for its user until enable confirms it with a
 * code. Each setup replaces the previous one; it expires after ten minutes.
 */
final readonly class PendingTotpSecrets
{
    public const int LIFETIME_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function hold(Uuid $userId, string $secret): void
    {
        $item = $this->cache->getItem(self::key($userId));
        $item->set($secret);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    public function find(Uuid $userId): ?string
    {
        $item = $this->cache->getItem(self::key($userId));
        $secret = $item->isHit() ? $item->get() : null;

        return is_string($secret) ? $secret : null;
    }

    public function forget(Uuid $userId): void
    {
        $this->cache->deleteItem(self::key($userId));
    }

    private static function key(Uuid $userId): string
    {
        return 'totp_pending_secret_' . $userId->toString();
    }
}
