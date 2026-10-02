<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Event\Outbox\OutboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class OutboxLeaseTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        $this->schema = 'outbox_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        $this->first->executeStatement('CREATE TABLE domain_event_outbox (
            id BIGSERIAL PRIMARY KEY, event_class TEXT NOT NULL, event_name TEXT NOT NULL,
            payload JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL, relayed_at TIMESTAMPTZ,
            attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TIMESTAMPTZ, dead_lettered_at TIMESTAMPTZ
        )');
        require_once dirname(__DIR__, 2) . '/migrations/Version20261001171000.php';
        $migration = new \DoctrineMigrations\Version20261001171000($this->first, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->first->close();
            $this->second->close();
        }
    }

    public function testClaimsSurviveCommitAndExpiredClaimsCannotAcknowledgeNewOwners(): void
    {
        $first = new OutboxRepository($this->first);
        $second = new OutboxRepository($this->second);
        $first->append('Event', 'test', []);
        $claim = $first->fetchPending(1)[0];
        self::assertFalse($this->first->isTransactionActive());
        self::assertSame([], $second->fetchPending(1));

        $this->first->executeStatement("UPDATE domain_event_outbox SET lease_until = NOW() - INTERVAL '1 second'");
        $replacement = $second->fetchPending(1)[0];
        self::assertSame($claim['id'], $replacement['id']);
        self::assertNotSame($claim['lease_token'], $replacement['lease_token']);
        self::assertFalse($first->markRelayed((int) $claim['id'], $claim['lease_token']));
        self::assertFalse($first->recordFailure((int) $claim['id'], 5, null, new \DateTimeImmutable(), $claim['lease_token']));
        self::assertTrue($second->markRelayed((int) $replacement['id'], $replacement['lease_token']));
        self::assertSame([], $first->fetchPending(1));
    }

    public function testRetryBackoffAndDeadLettersAreNotClaimed(): void
    {
        $repository = new OutboxRepository($this->first);
        $repository->append('Event', 'test', []);
        $claim = $repository->fetchPending(1)[0];
        self::assertTrue($repository->recordFailure((int) $claim['id'], 1, new \DateTimeImmutable('+1 hour'), null, $claim['lease_token']));
        self::assertSame([], (new OutboxRepository($this->second))->fetchPending(1));
        $this->first->executeStatement('UPDATE domain_event_outbox SET next_attempt_at = NULL');
        $claim = $repository->fetchPending(1)[0];
        self::assertTrue($repository->recordFailure((int) $claim['id'], 5, null, new \DateTimeImmutable(), $claim['lease_token']));
        self::assertSame([], $repository->fetchPending(1));
    }

    public function testConcurrentClaimsSkipLockedRowsAndRemainReservedAfterCommit(): void
    {
        $first = new OutboxRepository($this->first);
        $second = new OutboxRepository($this->second);
        $first->append('Event', 'first', []);
        $first->append('Event', 'second', []);
        $this->first->beginTransaction();
        try {
            $a = $first->fetchPending(1)[0];
            $b = $second->fetchPending(1)[0];
            self::assertNotSame($a['id'], $b['id']);
            $this->first->commit();
        } catch (\Throwable $error) {
            $this->first->rollBack();
            throw $error;
        }
        self::assertSame([], $second->fetchPending(1));
        self::assertTrue($first->renewLease((int) $a['id'], $a['lease_token']));
        self::assertFalse($second->renewLease((int) $a['id'], $b['lease_token']));
    }
}
