<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class QueryDateParameterTest extends TestCase
{
    public function testMissingDateIsNull(): void
    {
        self::assertNull(QueryParameters::optionalDate(new InputBag(), 'from'));
    }

    /** @return iterable<string, array{string}> */
    public static function validDates(): iterable
    {
        yield 'ordinary date' => ['2026-10-04'];
        yield 'leap day' => ['2024-02-29'];
        yield 'leap century' => ['2000-02-29'];
        yield 'year boundary' => ['2026-12-31'];
    }

    #[DataProvider('validDates')]
    public function testDatesPreserveCalendarDayAtMidnightInDefaultTimezone(string $value): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Copenhagen');

        try {
            $date = QueryParameters::optionalDate(new InputBag(['from' => $value]), 'from');

            self::assertInstanceOf(\DateTimeImmutable::class, $date);
            self::assertSame($value . ' 00:00:00.000000', $date->format('Y-m-d H:i:s.u'));
            self::assertSame('Europe/Copenhagen', $date->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDates(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['2026-10-04']];
        yield 'integer' => [20261004];
        yield 'boolean' => [true];
        yield 'relative' => ['yesterday'];
        yield 'malformed' => ['definitely-invalid-date'];
        yield 'non leap day' => ['2026-02-29'];
        yield 'non leap century' => ['1900-02-29'];
        yield 'overflow day' => ['2026-02-31'];
        yield 'overflow month' => ['2026-13-01'];
        yield 'zero day' => ['2026-10-00'];
        yield 'zero month' => ['2026-00-04'];
        yield 'zero year' => ['0000-01-01'];
        yield 'time' => ['2026-10-04 12:00:00'];
        yield 'ISO timestamp' => ['2026-10-04T00:00:00Z'];
        yield 'unpadded month' => ['2026-1-04'];
        yield 'unpadded day' => ['2026-10-4'];
        yield 'leading whitespace' => [' 2026-10-04'];
        yield 'trailing whitespace' => ['2026-10-04 '];
        yield 'NUL' => ["2026-10-04\0"];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateIdentifiesTheParameter(mixed $value): void
    {
        try {
            QueryParameters::optionalDate(new InputBag(['to' => $value]), 'to');
            self::fail('Expected invalid date to be rejected.');
        } catch (InvalidQueryParameter $exception) {
            self::assertSame('to', $exception->parameter);
            self::assertSame(400, $exception->getStatusCode());
        }
    }
}
