<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Exception\DatabaseTimeZoneException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Refuses a database whose default time zone is not UTC.
 *
 * Every connection sets its session zone to UTC on connect, so `SHOW TimeZone` answers UTC
 * whatever the server's default is. The default is `reset_val` of `TimeZone` in `pg_settings`:
 * the zone a session starts with, from the server configuration and any database or role
 * setting, before the connection's own `SET TIME ZONE`. Through the pooler that is the server
 * connection's start value; a direct libpq client with `PGTZ` set would report its own zone,
 * and the application sets none.
 */
#[Exclude]
final class DatabaseTimeZoneGuard
{
    /** UTC and its IANA links. PostgreSQL matches zone names regardless of case. */
    private const array UTC_NAMES = ['utc', 'etc/utc', 'uct', 'etc/uct', 'universal', 'etc/universal', 'zulu', 'etc/zulu'];

    /** @throws DatabaseTimeZoneException */
    public static function assertUtcDefault(Connection $connection): void
    {
        $zone = $connection->fetchOne("SELECT reset_val FROM pg_settings WHERE name = 'TimeZone'");
        if (!is_string($zone) || !in_array(strtolower($zone), self::UTC_NAMES, true)) {
            throw DatabaseTimeZoneException::notUtc(is_string($zone) ? $zone : null);
        }
    }

    /**
     * Checks the database at $databaseUrl on a connection of its own, closed before returning.
     *
     * @throws DatabaseTimeZoneException
     */
    public static function assertUtcDefaultAt(#[\SensitiveParameter] string $databaseUrl): void
    {
        $connection = DedicatedConnectionFactory::fromUrl($databaseUrl);
        try {
            self::assertUtcDefault($connection);
        } finally {
            $connection->close();
        }
    }
}
