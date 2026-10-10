<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Infrastructure\Event\NotificationDeliveryRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The event outbox and the notification delivery outbox write their instants by hand-written
 * SQL: each stores the exact instant, to the microsecond, in any session zone and from a PHP
 * value in any zone.
 */
final class OutboxInstantPrecisionTest extends KernelTestCase
{
    private const string LEASE = 'lease-instant-precision';

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    /** @return iterable<string, array{?string}> */
    public static function sessionZones(): iterable
    {
        yield 'UTC session' => [null];
        // Transaction-scoped: the test transaction rolls it back with the rows.
        yield 'Kiritimati session' => ['Pacific/Kiritimati'];
    }

    #[DataProvider('sessionZones')]
    public function testAnAppendedEventRecordsItsCreationInstant(?string $zone): void
    {
        $connection = $this->connection($zone);
        $before = self::now();

        (new OutboxRepository($connection))->append('App\\Tests\\ProbeEvent', 'probe.instant_precision', ['probe' => true]);

        $after = self::now();
        $createdAt = $connection->fetchOne(
            "SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') FROM domain_event_outbox WHERE event_name = 'probe.instant_precision'",
        );
        // Microsecond text compares in time order; a whole-second value falls before the window.
        self::assertIsString($createdAt);
        self::assertGreaterThanOrEqual($before, $createdAt);
        self::assertLessThanOrEqual($after, $createdAt);
    }

    #[DataProvider('sessionZones')]
    public function testAFailedEventRecordsItsRetryAndDeadLetterInstants(?string $zone): void
    {
        $connection = $this->connection($zone);
        $outbox = new OutboxRepository($connection);
        $outbox->append('App\\Tests\\ProbeEvent', 'probe.instant_precision', ['probe' => true]);
        $id = $this->lease($connection, 'domain_event_outbox', "event_name = 'probe.instant_precision'");

        self::assertTrue($outbox->recordFailure($id, 5, self::retryAt(), self::deadLetteredAt(), self::LEASE));

        self::assertSame(self::expected(), $this->failureInstants($connection, 'domain_event_outbox', $id));
    }

    #[DataProvider('sessionZones')]
    public function testAFailedDeliveryRecordsItsRetryAndDeadLetterInstants(?string $zone): void
    {
        $connection = $this->connection($zone);
        $deliveries = new NotificationDeliveryRepository($connection);
        $deliveries->append('email', 'instant-precision-probe', '{}');
        $id = $this->lease($connection, 'domain_event_outbox_delivery', "notification_id = 'instant-precision-probe'");

        self::assertTrue($deliveries->recordFailure($id, 5, self::retryAt(), self::deadLetteredAt(), self::LEASE));

        self::assertSame(self::expected(), $this->failureInstants($connection, 'domain_event_outbox_delivery', $id));
    }

    private function connection(?string $zone): Connection
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        if ($zone !== null) {
            $connection->executeStatement("SET LOCAL TIME ZONE '" . $zone . "'");
            self::assertSame($zone, $connection->fetchOne('SHOW TIME ZONE'));
        }

        return $connection;
    }

    private function lease(Connection $connection, string $table, string $where): int
    {
        $id = $connection->fetchOne(
            "UPDATE {$table} SET lease_token = :lease, lease_until = NOW() + INTERVAL '1 minute' WHERE {$where} RETURNING id",
            ['lease' => self::LEASE],
        );
        self::assertIsInt($id);

        return $id;
    }

    /** @return array{next_attempt_at: string, dead_lettered_at: string} */
    private function failureInstants(Connection $connection, string $table, int $id): array
    {
        $row = $connection->fetchAssociative(
            "SELECT to_char(next_attempt_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS next_attempt_at,
                    to_char(dead_lettered_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS dead_lettered_at
             FROM {$table} WHERE id = :id",
            ['id' => $id],
        );
        self::assertIsArray($row);

        return ['next_attempt_at' => (string) $row['next_attempt_at'], 'dead_lettered_at' => (string) $row['dead_lettered_at']];
    }

    /** 2030-01-02 03:04:05.123456 UTC, given in a zone whose offset is not a whole hour. */
    private static function retryAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2030-01-02 08:49:05.123456', new \DateTimeZone('Asia/Kathmandu'));
    }

    /** 2030-01-02 03:04:06.654321 UTC. */
    private static function deadLetteredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2030-01-01 23:34:06.654321', new \DateTimeZone('America/St_Johns'));
    }

    /** @return array{next_attempt_at: string, dead_lettered_at: string} */
    private static function expected(): array
    {
        return ['next_attempt_at' => '2030-01-02 03:04:05.123456', 'dead_lettered_at' => '2030-01-02 03:04:06.654321'];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
