<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\PhpDateTimeMappingType;
use Doctrine\DBAL\Types\Type;

/**
 * An instant stored as PostgreSQL `timestamptz` at its native microsecond precision.
 *
 * Registered under Doctrine's own `datetime_immutable` and `datetimetz_immutable` names, so
 * every mapping, DQL-inferred parameter and typed bind uses it. Doctrine's types write
 * `Y-m-d H:i:s` (no offset, no fraction) or `Y-m-d H:i:sO` (no fraction) and read the value
 * in the session's offset. This one writes the UTC instant with its offset and microseconds,
 * so neither PHP's zone nor the session's zone can shift it, and reads it back in UTC, so
 * `format(DATE_ATOM)` keeps printing `+00:00`.
 */
final class InstantType extends Type implements PhpDateTimeMappingType
{
    /** An instant as PostgreSQL parses it exactly: microseconds and the offset. */
    public const string WRITE_FORMAT = 'Y-m-d H:i:s.uP';

    /** PostgreSQL omits a zero fraction; a zone-less value, as from a `timestamp` column, is UTC. */
    private const array READ_FORMATS = ['!Y-m-d H:i:s.uP', '!Y-m-d H:i:sP', '!Y-m-d H:i:s.u', '!Y-m-d H:i:s'];

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMPTZ';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(self::utc())->format(self::WRITE_FORMAT);
        }

        throw InvalidType::new($value, self::class, ['null', DateTimeImmutable::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(self::utc());
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', DateTimeImmutable::class]);
        }

        foreach (self::READ_FORMATS as $format) {
            $instant = DateTimeImmutable::createFromFormat($format, $value, self::utc());
            // A warning means PHP rolled an impossible date over, such as month 13.
            if ($instant !== false && DateTimeImmutable::getLastErrors() === false) {
                return $instant->setTimezone(self::utc());
            }
        }

        throw InvalidFormat::new($value, self::class, self::WRITE_FORMAT);
    }

    private static function utc(): DateTimeZone
    {
        static $utc = new DateTimeZone('UTC');

        return $utc;
    }
}
