<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

use App\Shared\Interface\Exception\InvalidQueryParameter;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\InputBag;

final class QueryParameters
{
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
