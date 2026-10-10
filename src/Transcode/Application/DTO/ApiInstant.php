<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

/** A `timestamptz` value as PostgreSQL prints it, in the API format: whole seconds at +00:00. */
final class ApiInstant
{
    public static function fromDatabase(mixed $value): string
    {
        return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
