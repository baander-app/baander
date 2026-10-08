<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The days and result limit the admin activity analytics cover, read with one set of rules for
 * the /api/admin/activity/* query parameters and the app:activity:* options.
 *
 * Both dates are inclusive calendar days (Y-m-d) in the server time zone, so the range is the
 * instants [from, to) and ends at the start of the day after the last day. That boundary does
 * not depend on how finely last_played_at is stored, unlike an inclusive end at 23:59:59.
 */
final readonly class ActivityAnalyticsRange
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 100;

    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    /**
     * Without `from` the range starts 30 days before now; without `to` the last day is today.
     *
     * @param InputBag<covariant string|int|float|bool|null> $query
     *
     * @throws InvalidQueryParameter when a date is malformed or the last day precedes the first
     */
    public static function fromQuery(InputBag $query): self
    {
        $from = QueryParameters::optionalDate($query, 'from') ?? new \DateTimeImmutable('-30 days');
        $lastDay = QueryParameters::optionalDate($query, 'to') ?? new \DateTimeImmutable('today');
        $to = $lastDay->modify('+1 day');

        if ($from >= $to) {
            throw new InvalidQueryParameter('to', 'End date must not precede start date.');
        }

        return new self($from, $to);
    }

    /**
     * The number of top tracks or artists: 1 to MAX_LIMIT, DEFAULT_LIMIT when absent.
     *
     * @param InputBag<covariant string|int|float|bool|null> $query
     *
     * @throws InvalidQueryParameter when the limit is not an integer in range
     */
    public static function limit(InputBag $query): int
    {
        return QueryParameters::integer($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
    }
}
