<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/** The database's default time zone is not UTC. The message names the zone, never connection details. */
final class DatabaseTimeZoneException extends \RuntimeException
{
    public static function notUtc(?string $zone): self
    {
        return new self(sprintf(
            "PostgreSQL's default time zone is %s, not UTC. Set timezone = 'UTC' in postgresql.conf, run the database migrations and remove any role time zone setting.",
            $zone === null ? 'unknown' : "'" . $zone . "'",
        ));
    }
}
