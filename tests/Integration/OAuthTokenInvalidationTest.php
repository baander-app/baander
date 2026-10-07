<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Exception\OAuthTokenCacheInvalidationFailed;
use App\Auth\Infrastructure\Security\OAuth\DoctrineOAuthTokenInvalidator;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Cache\CacheTags;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/** Actual migration tables and foreign keys, observed outside the maintenance transaction. */
final class OAuthTokenInvalidationTest extends TestCase
{
    private const array GRANTS = ['oauth_token_metadata', 'oauth_refresh_tokens', 'oauth_access_tokens'];
    private Connection $writer;
    private Connection $observer;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $params['wrapperClass'] = OAuthInvalidationCommitThenThrowConnection::class;
        $this->writer = DriverManager::getConnection($params);
        unset($params['wrapperClass']);
        $this->observer = DriverManager::getConnection($params);
        self::assertStringStartsWith('18.', (string) $this->writer->fetchOne('SHOW server_version'));
        $this->writer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->schema = 'oauth_invalidation_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        // Apply the affected SQL from the real migrations, preserving its types,
        // indexes and FKs rather than inventing a relaxed test-only schema.
        foreach (['Version001_InitialSchema', 'Version20260716235039'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            if (!class_exists($class)) {
                throw new LogicException('The migration fixture class was not loaded.');
            }
            $migration = new $class($this->writer, new NullLogger());
            self::assertInstanceOf(AbstractMigration::class, $migration);
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $sql = $query->getStatement();
                if (preg_match('/\ACREATE TABLE (?:oauth_[a-z_]+|users)\b/', $sql) === 1
                    || preg_match('/\AALTER TABLE oauth_[a-z_]+\b/', $sql) === 1
                    || preg_match('/\ACREATE (?:UNIQUE )?INDEX [a-z_]+ ON oauth_[a-z_]+\b/', $sql) === 1
                    || $sql === 'DROP INDEX idx_oauth_device_codes_user_code'
                ) {
                    $this->writer->executeStatement($sql, $query->getParameters(), $query->getTypes());
                }
            }
        }
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            if ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->schema, $this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
    }

    public function testDeletesEveryGrantAndMetadataBeforeCacheInvalidationWhilePreservingPrincipals(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::once())->method('invalidateTags')->with([CacheTags::OAUTH_TOKEN])->willReturnCallback(function (): bool {
            $this->assertGrantRows(0);
            self::assertFalse($this->writer->isTransactionActive());

            return true;
        });
        self::assertSame(4, new DoctrineOAuthTokenInvalidator($this->writer, $cache)->invalidate());
        $this->assertPrincipalsRemain();
        $this->assertGrantRows(0);
    }

    public function testFailureAfterEarlierDeletesRollsBackEverythingAndDoesNotTouchCache(): void
    {
        $this->writer->executeStatement(<<<'SQL'
            CREATE FUNCTION reject_oauth_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'injected-private-failure'; END $$
            SQL);
        // Access tokens are deleted last, after their refresh tokens and metadata.
        $this->writer->executeStatement('CREATE TRIGGER reject_oauth_delete BEFORE DELETE ON oauth_access_tokens FOR EACH ROW EXECUTE FUNCTION reject_oauth_delete()');
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::never())->method('invalidateTags');
        try {
            new DoctrineOAuthTokenInvalidator($this->writer, $cache)->invalidate();
            self::fail('The actual PostgreSQL trigger must abort the operation.');
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('injected-private-failure', (string) $error);
        }
        self::assertFalse($this->writer->isConnected());
        $this->assertGrantRows(4);
        $this->assertPrincipalsRemain();
    }

    #[DataProvider('cacheFailures')]
    public function testCacheFailureReportsCommittedDeletionAndOfflineRetryDeletesNoAdditionalRows(bool $throw): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::once())->method('invalidateTags')->with([CacheTags::OAUTH_TOKEN])->willReturnCallback(static function () use ($throw): bool {
            if ($throw) {
                throw new RuntimeException('injected-private-cache-failure');
            }

            return false;
        });
        try {
            new DoctrineOAuthTokenInvalidator($this->writer, $cache)->invalidate();
            self::fail('Unconfirmed cache invalidation must report the committed phase.');
        } catch (OAuthTokenCacheInvalidationFailed $error) {
            self::assertSame(4, $error->deletedRows);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('injected-private-cache-failure', (string) $error);
        }
        $this->assertGrantRows(0);
        $retryCache = $this->createMock(TagAwareCacheInterface::class);
        $retryCache->expects(self::once())->method('invalidateTags')->with([CacheTags::OAUTH_TOKEN])->willReturn(true);
        self::assertSame(0, new DoctrineOAuthTokenInvalidator($this->writer, $retryCache)->invalidate());
        $this->assertPrincipalsRemain();
    }

    /** @return iterable<string,array{bool}> */
    public static function cacheFailures(): iterable
    {
        yield 'false response' => [false];
        yield 'exception' => [true];
    }

    public function testLostCommitAcknowledgmentPreservesDeletionAndOfflineRetryIsSafe(): void
    {
        self::assertInstanceOf(OAuthInvalidationCommitThenThrowConnection::class, $this->writer);
        $this->writer->throwAfterCommit = true;
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::never())->method('invalidateTags');
        try {
            new DoctrineOAuthTokenInvalidator($this->writer, $cache)->invalidate();
            self::fail('A lost acknowledgment cannot be reported as confirmed.');
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('injected-private-commit-failure', (string) $error);
        }
        self::assertFalse($this->writer->isConnected());
        $this->assertGrantRows(0);
        $retryCache = $this->createMock(TagAwareCacheInterface::class);
        $retryCache->expects(self::once())->method('invalidateTags')->with([CacheTags::OAUTH_TOKEN])->willReturn(true);
        self::assertSame(0, new DoctrineOAuthTokenInvalidator($this->observer, $retryCache)->invalidate());
        $this->assertPrincipalsRemain();
    }

    public function testRejectsNestedTransactionWithoutRollingBackCallerOrTouchingCache(): void
    {
        $this->writer->beginTransaction();
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::never())->method('invalidateTags');
        try {
            new DoctrineOAuthTokenInvalidator($this->writer, $cache)->invalidate();
            self::fail('The invalidator must own its commit boundary.');
        } catch (LogicException $error) {
            self::assertStringContainsString('idle autocommit', $error->getMessage());
        }
        self::assertSame(1, $this->writer->getTransactionNestingLevel());
        self::assertTrue($this->writer->isConnected());
        $this->assertGrantRows(4);
    }

    public function testLockTimeoutRollsBackAndDiscardsDedicatedConnection(): void
    {
        $this->observer->beginTransaction();
        $this->observer->executeStatement('LOCK TABLE oauth_access_tokens IN ACCESS EXCLUSIVE MODE');
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::never())->method('invalidateTags');
        try {
            new DoctrineOAuthTokenInvalidator($this->writer, $cache, statementTimeoutMs: 1000, lockTimeoutMs: 50)->invalidate();
            self::fail('An independent PostgreSQL lock must time out.');
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
        } finally {
            $this->observer->rollBack();
        }
        self::assertFalse($this->writer->isConnected());
        $this->assertGrantRows(4);
    }

    private function seed(): void
    {
        $now = '2026-10-03 12:00:00+00:00';
        $user = Uuid::generate()->toString();
        $client = Uuid::generate()->toString();
        $access = Uuid::generate()->toString();
        $refresh = Uuid::generate()->toString();
        $times = ['created_at' => $now, 'updated_at' => $now];
        $this->writer->insert('users', ['id' => $user, 'public_id' => 'rotation-user', 'name' => 'Rotation test',
            'email' => 'rotation@baander.app', 'password' => 'test-only', ...$times]);
        $this->writer->insert('oauth_clients', ['id' => $client, 'public_id' => 'rotation-client', 'name' => 'Rotation test',
            'redirect' => '["https://baander.app/callback"]', ...$times]);
        $this->writer->insert('oauth_scopes', ['id' => 'profile', 'description' => 'Profile', ...$times]);
        $this->writer->insert('oauth_access_tokens', ['id' => $access, 'token_id' => 'rotation-access-token',
            'client_id' => $client, 'user_id' => $user, ...$times]);
        $this->writer->insert('oauth_refresh_tokens', ['id' => $refresh, 'token_id' => 'rotation-refresh-token',
            'access_token_id' => $access, ...$times]);
        $this->writer->insert('oauth_refresh_tokens', ['id' => Uuid::generate()->toString(), 'token_id' => 'rotation-refresh-successor',
            'access_token_id' => $access, 'previous_refresh_token_id' => $refresh, ...$times]);
        $this->writer->insert('oauth_token_metadata', ['id' => Uuid::generate()->toString(), 'token_id' => $access, ...$times]);
        $this->assertGrantRows(4);
    }

    private function assertGrantRows(int $expected): void
    {
        $total = 0;
        foreach (self::GRANTS as $table) {
            $count = (int) $this->observer->fetchOne('SELECT count(*) FROM ' . $table);
            if ($expected === 0) {
                self::assertSame(0, $count, $table);
            }
            $total += $count;
        }
        self::assertSame($expected, $total);
    }

    private function assertPrincipalsRemain(): void
    {
        foreach (['users', 'oauth_clients', 'oauth_scopes'] as $table) {
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM ' . $table), $table);
        }
    }
}

/** Real PostgreSQL commits first; only its acknowledgment is lost. */
final class OAuthInvalidationCommitThenThrowConnection extends Connection
{
    public bool $throwAfterCommit = false;

    public function commit(): void
    {
        parent::commit();
        if ($this->throwAfterCommit) {
            $this->throwAfterCommit = false;
            throw new RuntimeException('injected-private-commit-failure');
        }
    }
}
