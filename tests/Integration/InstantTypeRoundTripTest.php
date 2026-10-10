<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An instant written and read through Doctrine's instant type names keeps its instant and its
 * microseconds when neither PHP's zone nor the session's zone is UTC.
 */
final class InstantTypeRoundTripTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }

        CustomTypesRegistrar::register();
        $this->connection = DedicatedConnectionFactory::fromUrl($url);
        $this->connection->executeStatement('CREATE TEMPORARY TABLE instant_round_trip (id int PRIMARY KEY, at timestamptz)');
        $this->connection->executeStatement("SET TIME ZONE 'America/New_York'");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function typeNames(): iterable
    {
        yield Types::DATETIME_IMMUTABLE => [Types::DATETIME_IMMUTABLE];
        yield Types::DATETIMETZ_IMMUTABLE => [Types::DATETIMETZ_IMMUTABLE];
    }

    #[DataProvider('typeNames')]
    public function testAKathmanduInstantSurvivesANewYorkSession(string $type): void
    {
        $kathmandu = new \DateTimeImmutable('2026-10-11 03:44:59.654321', new \DateTimeZone('Asia/Kathmandu'));

        $this->connection->insert('instant_round_trip', ['id' => 1, 'at' => $kathmandu], ['id' => Types::INTEGER, 'at' => $type]);

        self::assertSame(
            '2026-10-10 21:59:59.654321',
            $this->connection->fetchOne("SELECT (at AT TIME ZONE 'UTC')::text FROM instant_round_trip WHERE id = 1"),
        );
        $read = $this->connection->convertToPHPValue($this->connection->fetchOne('SELECT at FROM instant_round_trip WHERE id = 1'), $type);
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('2026-10-10T21:59:59.654321+00:00', $read->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-10-10T21:59:59+00:00', $read->format(DATE_ATOM));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM instant_round_trip WHERE at = :at', ['at' => $kathmandu], ['at' => $type]));
    }

    #[DataProvider('typeNames')]
    public function testNullRoundTripsAsNull(string $type): void
    {
        $this->connection->insert('instant_round_trip', ['id' => 2, 'at' => null], ['id' => Types::INTEGER, 'at' => $type]);

        self::assertNull($this->connection->convertToPHPValue($this->connection->fetchOne('SELECT at FROM instant_round_trip WHERE id = 2'), $type));
    }
}
