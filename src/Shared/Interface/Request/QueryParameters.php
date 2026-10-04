<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\InputBag;

final class QueryParameters
{
    /** @param InputBag<covariant string|int|float|bool|null> $query */
    public static function optionalDateTime(InputBag $query, string $name): ?\DateTimeImmutable
    {
        if (!$query->has($name)) {
            return null;
        }

        $value = $query->all()[$name];
        if (is_string($value)
            && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                if ((int) $date->setTimezone(new \DateTimeZone('UTC'))->format('Y') < 1) {
                    throw new InvalidQueryParameter($name, sprintf('%s must represent a UTC instant in year 0001 or later.', $name));
                }

                return $date;
            }
        }

        throw new InvalidQueryParameter($name, sprintf('%s must be an RFC 3339 timestamp with a timezone and at most six fractional digits.', $name));
    }

    /** @param InputBag<covariant string|int|float|bool|null> $query */
    public static function optionalUuid(InputBag $query, string $name): ?Uuid
    {
        if (!$query->has($name)) {
            return null;
        }

        $value = $query->all()[$name];
        if (is_string($value)) {
            try {
                return Uuid::fromString($value);
            } catch (\InvalidArgumentException) {
                // Map only malformed input to the shared query error contract.
            }
        }

        throw new InvalidQueryParameter($name, sprintf('%s must be a UUID.', $name));
    }

    /** @param InputBag<covariant string|int|float|bool|null> $query */
    public static function optionalDate(InputBag $query, string $name): ?\DateTimeImmutable
    {
        if (!$query->has($name)) {
            return null;
        }

        $value = $query->all()[$name];
        if (is_string($value)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        throw new InvalidQueryParameter($name, sprintf('%s must be a valid date in Y-m-d format.', $name));
    }

    /**
     * @param InputBag<covariant string|int|float|bool|null> $query
     */
    public static function pagination(InputBag $query, int $defaultLimit = 50, int $maximumLimit = 100): OffsetPagination
    {
        return new OffsetPagination(
            self::integer($query, 'limit', $defaultLimit, 1, $maximumLimit),
            self::integer($query, 'offset', 0, 0),
        );
    }

    /**
     * @param InputBag<covariant string|int|float|bool|null> $query
     */
    public static function integer(InputBag $query, string $name, int $default, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        try {
            $value = $query->getInt($name, $default);
        } catch (BadRequestException) {
            throw new InvalidQueryParameter($name, sprintf('%s must be an integer.', $name));
        }

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidQueryParameter($name, sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
        }

        return $value;
    }

    /**
     * @param InputBag<covariant string|int|float|bool|null> $query
     * @param list<string> $choices
     */
    public static function optionalChoice(InputBag $query, string $name, array $choices): ?string
    {
        if (!$query->has($name)) {
            return null;
        }

        $value = $query->all()[$name];
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidQueryParameter($name, sprintf('%s must be one of: %s.', $name, implode(', ', $choices)));
        }

        return $value;
    }

    /**
     * @param InputBag<covariant string|int|float|bool|null> $query
     */
    public static function optionalBoolean(InputBag $query, string $name): ?bool
    {
        if (!$query->has($name)) {
            return null;
        }

        $value = $query->all()[$name];
        return match ($value) {
            'true', '1', true, 1 => true,
            'false', '0', false, 0 => false,
            default => throw new InvalidQueryParameter($name, sprintf('%s must be true, false, 1 or 0.', $name)),
        };
    }
}
