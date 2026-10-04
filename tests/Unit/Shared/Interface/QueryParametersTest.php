<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class QueryParametersTest extends TestCase
{
    public function testPaginationDefaultsAndCustomBounds(): void
    {
        $defaults = QueryParameters::pagination(new InputBag());
        self::assertSame(50, $defaults->limit);
        self::assertSame(0, $defaults->offset);

        $custom = QueryParameters::pagination(new InputBag(), 25, 30);
        self::assertSame(25, $custom->limit);
        self::assertSame(0, $custom->offset);

        $bounds = QueryParameters::pagination(new InputBag(['limit' => '100', 'offset' => (string) PHP_INT_MAX]));
        self::assertSame(100, $bounds->limit);
        self::assertSame(PHP_INT_MAX, $bounds->offset);
        self::assertSame(1, QueryParameters::pagination(new InputBag(['limit' => '1']))->limit);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPagination(): iterable
    {
        foreach (['limit', 'offset'] as $name) {
            foreach (['-1', 'abc', '1.5', '1e2', '', '999999999999999999999999', ['1']] as $index => $value) {
                yield $name . '-' . $index => [$name, $value];
            }
        }
        yield 'zero limit' => ['limit', '0'];
        yield 'excess limit' => ['limit', '101'];
    }

    #[DataProvider('invalidPagination')]
    public function testInvalidPaginationIdentifiesTheParameter(string $name, mixed $value): void
    {
        try {
            QueryParameters::pagination(new InputBag([$name => $value]));
            self::fail('Expected invalid pagination to be rejected.');
        } catch (InvalidQueryParameter $exception) {
            self::assertSame($name, $exception->parameter);
            self::assertSame(400, $exception->getStatusCode());
        }
    }

    public function testConfiguredMaximumLimitIsEnforced(): void
    {
        $this->expectException(InvalidQueryParameter::class);
        QueryParameters::pagination(new InputBag(['limit' => '31']), 25, 30);
    }

    public function testMissingFiltersAndValidChoices(): void
    {
        self::assertNull(QueryParameters::optionalChoice(new InputBag(), 'role', ['ROLE_ADMIN']));
        self::assertNull(QueryParameters::optionalBoolean(new InputBag(), 'disabled'));
        self::assertSame('ROLE_ADMIN', QueryParameters::optionalChoice(new InputBag(['role' => 'ROLE_ADMIN']), 'role', ['ROLE_USER', 'ROLE_ADMIN']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidChoices(): iterable
    {
        yield 'unknown' => ['ROLE_UNKNOWN'];
        yield 'empty' => [''];
        yield 'array' => [['ROLE_ADMIN']];
        yield 'null' => [null];
        yield 'integer' => [1];
    }

    #[DataProvider('invalidChoices')]
    public function testInvalidChoicesAreRejected(mixed $value): void
    {
        $this->expectException(InvalidQueryParameter::class);
        QueryParameters::optionalChoice(new InputBag(['role' => $value]), 'role', ['ROLE_ADMIN']);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleans(): iterable
    {
        foreach ([true, false] as $expected) {
            foreach ([$expected, (int) $expected, $expected ? 'true' : 'false', $expected ? '1' : '0'] as $index => $value) {
                yield ($expected ? 'true' : 'false') . '-' . $index => [$value, $expected];
            }
        }
    }

    #[DataProvider('booleans')]
    public function testBooleanFiltersPreserveFalse(mixed $value, bool $expected): void
    {
        self::assertSame($expected, QueryParameters::optionalBoolean(new InputBag(['disabled' => $value]), 'disabled'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidBooleans(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'unknown' => ['invalid'];
        yield 'array' => [['true']];
        yield 'float' => [1.0];
        yield 'other integer' => [2];
    }

    #[DataProvider('invalidBooleans')]
    public function testInvalidBooleansAreRejected(mixed $value): void
    {
        $this->expectException(InvalidQueryParameter::class);
        QueryParameters::optionalBoolean(new InputBag(['disabled' => $value]), 'disabled');
    }
}
