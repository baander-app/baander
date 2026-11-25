<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The secret a TOTP setup generated, held for its user until enable confirms it with a
 * code. Each setup replaces the previous one; it expires after ten minutes.
 *
 * The cache holds the secret sealed with the TOTP cipher, as the users table does, so the
 * cache store never holds a secret that would produce the user's codes. A held secret that
 * no longer opens, as after a change of the TOTP key, counts as no setup.
 */
final readonly class PendingTotpSecrets
{
    public const int LIFETIME_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private TotpSecretCipherInterface $cipher,
    ) {
    }

    public function hold(Uuid $userId, string $secret): void
    {
        $item = $this->cache->getItem(self::key($userId));
        $item->set($this->cipher->seal($secret));
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    public function find(Uuid $userId): ?string
    {
        $item = $this->cache->getItem(self::key($userId));
        $sealed = $item->isHit() ? $item->get() : null;

        return is_string($sealed) ? $this->open($sealed) : null;
    }

    public function forget(Uuid $userId): void
    {
        $this->cache->deleteItem(self::key($userId));
    }

    private function open(string $sealed): ?string
    {
        try {
            return $this->cipher->open($sealed);
        } catch (TotpSecretUnavailableException) {
            return null;
        }
    }

    private static function key(Uuid $userId): string
    {
        return 'totp_pending_secret_' . $userId->toString();
    }
}
