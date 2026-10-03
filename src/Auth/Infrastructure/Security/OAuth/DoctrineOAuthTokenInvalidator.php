<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\OAuthTokenCacheInvalidationFailed;
use App\Auth\Application\Port\OAuthTokenInvalidatorInterface;
use App\Shared\Infrastructure\Cache\CacheTags;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

/** Offline maintenance only; transaction locks do not fence workers after commit. */
final readonly class DoctrineOAuthTokenInvalidator implements OAuthTokenInvalidatorInterface
{
    private const array TABLES = [
        'oauth_token_metadata', 'oauth_refresh_tokens', 'oauth_auth_codes',
        'oauth_device_codes', 'oauth_access_tokens',
    ];

    public function __construct(
        private Connection $connection,
        private TagAwareCacheInterface $cache,
        private int $statementTimeoutMs = 5000,
        private int $lockTimeoutMs = 1000,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new InvalidArgumentException('OAuth invalidation requires bounded positive transaction timeouts.');
        }
    }

    public function invalidate(): int
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new LogicException('OAuth invalidation requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->connection->executeQuery(
                "SELECT set_config('lock_timeout', :lock, true), set_config('statement_timeout', :statement, true)",
                ['lock' => $this->lockTimeoutMs . 'ms', 'statement' => $this->statementTimeoutMs . 'ms'],
            )->free();
            // Use one fixed order across maintenance callers. These locks only
            // bound concurrent writes during this transaction, not future issuance.
            $this->connection->executeStatement(
                'LOCK TABLE ' . implode(', ', self::TABLES) . ' IN SHARE ROW EXCLUSIVE MODE',
            );
            $deletedRows = 0;
            foreach (self::TABLES as $table) {
                $deletedRows += $this->connection->executeStatement('DELETE FROM ' . $table);
            }
            $this->connection->commit();
        } catch (Throwable) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (Throwable) {
            } finally {
                $this->connection->close();
            }
            throw new RuntimeException('OAuth token deletion was not confirmed; keep all workers offline.');
        }

        try {
            $invalidated = $this->cache->invalidateTags([CacheTags::OAUTH_TOKEN]);
        } catch (Throwable) {
            throw new OAuthTokenCacheInvalidationFailed($deletedRows);
        }
        if (!$invalidated) {
            throw new OAuthTokenCacheInvalidationFailed($deletedRows);
        }

        return $deletedRows;
    }
}
