<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class QueryInstantParameterTest extends TestCase
{
    public function testMissingInstantAndUuidAreNull(): void
    {
        self::assertNull(QueryParameters::optionalDateTime(new InputBag(), 'since'));
        self::assertNull(QueryParameters::optionalUuid(new InputBag(), 'cursor'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function validInstants(): iterable
    {
        yield 'UTC' => ['2026-10-04T12:34:56Z', '2026-10-04 12:34:56.000000'];
        yield 'positive offset' => ['2026-10-04T14:34:56+02:00', '2026-10-04 12:34:56.000000'];
        yield 'negative offset' => ['2026-10-04T07:04:56-05:30', '2026-10-04 12:34:56.000000'];
        yield 'one fractional digit' => ['2026-10-04T12:34:56.1Z', '2026-10-04 12:34:56.100000'];
        yield 'six fractional digits' => ['2026-10-04T14:34:56.123456+02:00', '2026-10-04 12:34:56.123456'];
        yield 'leap day' => ['2024-02-29T00:00:00Z', '2024-02-29 00:00:00.000000'];
        yield 'leap century' => ['2000-02-29T00:00:00Z', '2000-02-29 00:00:00.000000'];
        yield 'first supported year' => ['0001-01-01T00:00:00Z', '0001-01-01 00:00:00.000000'];
        yield 'last supported year' => ['9999-12-31T23:59:59.999999Z', '9999-12-31 23:59:59.999999'];
    }

    #[DataProvider('validInstants')]
    public function testInstantPreservesItsUtcValueAndMicroseconds(string $value, string $expectedUtc): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Copenhagen');

        try {
            $instant = QueryParameters::optionalDateTime(new InputBag(['since' => $value]), 'since');

            self::assertInstanceOf(\DateTimeImmutable::class, $instant);
            self::assertSame($expectedUtc, $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidInstants(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['2026-10-04T12:34:56Z']];
        yield 'integer' => [1791117296];
        yield 'boolean' => [true];
        yield 'date only' => ['2026-10-04'];
        yield 'relative' => ['yesterday'];
        yield 'naive instant' => ['2026-10-04T12:34:56'];
        yield 'missing seconds' => ['2026-10-04T12:34Z'];
        yield 'space separator' => ['2026-10-04 12:34:56Z'];
        yield 'non leap day' => ['2026-02-29T12:34:56Z'];
        yield 'non leap century' => ['1900-02-29T12:34:56Z'];
        yield 'day rollover' => ['2026-02-31T12:34:56Z'];
        yield 'month rollover' => ['2026-13-04T12:34:56Z'];
        yield 'zero month' => ['2026-00-04T12:34:56Z'];
        yield 'zero day' => ['2026-10-00T12:34:56Z'];
        yield 'zero year' => ['0000-10-04T12:34:56Z'];
        yield 'UTC instant before first supported year' => ['0001-01-01T00:00:00+23:59'];
        yield 'expanded year' => ['10000-10-04T12:34:56Z'];
        yield 'hour rollover' => ['2026-10-04T24:00:00Z'];
        yield 'minute rollover' => ['2026-10-04T12:60:00Z'];
        yield 'second rollover' => ['2026-10-04T12:34:60Z'];
        yield 'offset hour rollover' => ['2026-10-04T12:34:56+24:00'];
        yield 'offset minute rollover' => ['2026-10-04T12:34:56+02:60'];
        yield 'compact offset' => ['2026-10-04T12:34:56+0200'];
        yield 'named timezone' => ['2026-10-04T12:34:56Europe/Copenhagen'];
        yield 'empty fractional part' => ['2026-10-04T12:34:56.Z'];
        yield 'excess fractional precision' => ['2026-10-04T12:34:56.1234567Z'];
        yield 'comma fractional separator' => ['2026-10-04T12:34:56,123Z'];
        yield 'unpadded month' => ['2026-1-04T12:34:56Z'];
        yield 'leading whitespace' => [' 2026-10-04T12:34:56Z'];
        yield 'trailing whitespace' => ['2026-10-04T12:34:56Z '];
        yield 'NUL' => ["2026-10-04T12:34:56Z\0"];
    }

    #[DataProvider('invalidInstants')]
    public function testInvalidInstantIdentifiesTheParameter(mixed $value): void
    {
        try {
            QueryParameters::optionalDateTime(new InputBag(['since' => $value]), 'since');
            self::fail('Expected invalid instant to be rejected.');
        } catch (InvalidQueryParameter $exception) {
            self::assertSame('since', $exception->parameter);
            self::assertSame(400, $exception->getStatusCode());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function validUuids(): iterable
    {
        yield 'UUID v4' => ['550e8400-e29b-41d4-a716-446655440000'];
        yield 'UUID v7' => ['019a1234-5678-7abc-9def-0123456789ab'];
    }

    #[DataProvider('validUuids')]
    public function testCursorUsesSharedUuidContract(string $value): void
    {
        $cursor = QueryParameters::optionalUuid(new InputBag(['cursor' => $value]), 'cursor');

        self::assertInstanceOf(Uuid::class, $cursor);
        self::assertTrue($cursor->equals(Uuid::fromString($value)));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['019a1234-5678-7abc-9def-0123456789ab']];
        yield 'integer' => [42];
        yield 'boolean' => [true];
        yield 'malformed UUID' => ['not-a-uuid'];
        yield 'public Nanoid' => ['0123456789abcdefghijk'];
        yield 'NUL' => ["019a1234-5678-7abc-9def-0123456789ab\0"];
    }

    #[DataProvider('invalidUuids')]
    public function testInvalidCursorIdentifiesTheParameter(mixed $value): void
    {
        try {
            QueryParameters::optionalUuid(new InputBag(['cursor' => $value]), 'cursor');
            self::fail('Expected invalid UUID to be rejected.');
        } catch (InvalidQueryParameter $exception) {
            self::assertSame('cursor', $exception->parameter);
            self::assertSame(400, $exception->getStatusCode());
        }
    }
}
