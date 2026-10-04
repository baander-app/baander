<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\OutboxRelayException;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
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
        $claim = $this->claimPending($first);
        self::assertFalse($this->first->isTransactionActive());
        self::assertSame([], $second->fetchPending(1));

        $this->first->executeStatement("UPDATE domain_event_outbox SET lease_until = NOW() - INTERVAL '1 second'");
        $replacement = $this->claimPending($second);
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
            $a = $this->claimPending($first);
            $b = $this->claimPending($second);
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

    public function testPoisonEventRetriesThenDeadLettersWithoutBlockingHealthyRows(): void
    {
        $repository = new OutboxRepository($this->first);
        $repository->append('App\\MissingOutboxEvent', 'missing.event', ['value' => 'poison']);
        $event = new \App\Auth\Domain\Event\UserRegistered(
            \App\Shared\Domain\Model\Uuid::v4(),
            \App\Shared\Domain\Model\PublicId::fromString('aaaaaaaaaaaaaaaaaaaaa'),
            \App\Shared\Domain\Model\Email::fromString('outbox@example.com'),
            'Outbox test',
        );
        $repository->append($event::class, $event->eventName(), $event->toPayload());
        $dispatcher = $this->createMock(\App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf($event::class), $this->greaterThan(0));
        $handler = new RelayOutboxHandler($repository, $dispatcher, new \Psr\Log\NullLogger());

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $handler(new RelayOutboxCommand());
                self::fail('Poison events must report a relay failure.');
            } catch (OutboxRelayException $error) {
                self::assertCount(1, $error->getFailures());
                self::assertSame('missing.event', $error->getFailures()[0]['event']);
            }
            $row = $this->second->fetchAssociative("SELECT * FROM domain_event_outbox WHERE event_name = 'missing.event'");
            self::assertNotFalse($row);
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertNull($row['relayed_at']);
            self::assertNull($row['lease_token']);
            self::assertNull($row['lease_until']);
            self::assertSame([], (new OutboxRepository($this->second))->fetchPending());
            if ($attempt < 5) {
                self::assertNotNull($row['next_attempt_at']);
                self::assertNull($row['dead_lettered_at']);
                // Advance only the retry deadline, without sleeping or bypassing claims.
                $this->first->executeStatement("UPDATE domain_event_outbox SET next_attempt_at = NOW() - INTERVAL '1 second' WHERE event_name = 'missing.event'");
            } else {
                self::assertNull($row['next_attempt_at']);
                self::assertNotNull($row['dead_lettered_at']);
            }
        }
        self::assertSame(1, (int) $this->second->fetchOne('SELECT COUNT(*) FROM domain_event_outbox WHERE relayed_at IS NOT NULL'));
        self::assertSame(0, $handler(new RelayOutboxCommand()));
    }

    /** @return array<string, mixed> */
    private function claimPending(OutboxRepository $repository): array
    {
        $claims = $repository->fetchPending(1);
        self::assertCount(1, $claims);
        self::assertArrayHasKey(0, $claims);

        return $claims[0];
    }

}
