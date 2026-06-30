<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Domain\Model;

use App\Playlist\Domain\Model\SmartPlaylist;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SmartPlaylistTest extends TestCase
{
    public function testParseEmptyRulesReturnsEmptyArray(): void
    {
        $result = SmartPlaylist::parseRules([]);

        $this->assertSame([], $result);
    }

    public function testParseValidEqualsRule(): void
    {
        $rules = [
            ['field' => 'genre', 'operator' => 'equals', 'value' => 'rock'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertCount(1, $result);
        $this->assertSame('genre', $result[0]['field']);
        $this->assertSame('equals', $result[0]['operator']);
        $this->assertSame('rock', $result[0]['value']);
    }

    public function testParseNotEqualsRule(): void
    {
        $rules = [
            ['field' => 'genre', 'operator' => 'not_equals', 'value' => 'pop'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('not_equals', $result[0]['operator']);
        $this->assertSame('pop', $result[0]['value']);
    }

    public function testParseContainsRule(): void
    {
        $rules = [
            ['field' => 'title', 'operator' => 'contains', 'value' => 'Love'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('contains', $result[0]['operator']);
        $this->assertSame('Love', $result[0]['value']);
    }

    public function testParseGreaterThanRule(): void
    {
        $rules = [
            ['field' => 'year', 'operator' => 'greater_than', 'value' => 2020],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('greater_than', $result[0]['operator']);
        $this->assertSame(2020, $result[0]['value']);
    }

    public function testParseLessThanRule(): void
    {
        $rules = [
            ['field' => 'duration', 'operator' => 'less_than', 'value' => 180],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('less_than', $result[0]['operator']);
        $this->assertSame(180, $result[0]['value']);
    }

    public function testParseIsEmptyRuleDoesNotRequireValue(): void
    {
        $rules = [
            ['field' => 'genre', 'operator' => 'is_empty'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('is_empty', $result[0]['operator']);
        $this->assertArrayNotHasKey('value', $result[0]);
    }

    public function testParseIsNotEmptyRuleDoesNotRequireValue(): void
    {
        $rules = [
            ['field' => 'genre', 'operator' => 'is_not_empty'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertSame('is_not_empty', $result[0]['operator']);
        $this->assertArrayNotHasKey('value', $result[0]);
    }

    public function testParseMultipleRules(): void
    {
        $rules = [
            ['field' => 'genre', 'operator' => 'equals', 'value' => 'rock'],
            ['field' => 'year', 'operator' => 'greater_than', 'value' => 2010],
            ['field' => 'artist', 'operator' => 'is_not_empty'],
        ];

        $result = SmartPlaylist::parseRules($rules);

        $this->assertCount(3, $result);
        $this->assertSame('genre', $result[0]['field']);
        $this->assertSame('equals', $result[0]['operator']);
        $this->assertSame('rock', $result[0]['value']);
        $this->assertSame('year', $result[1]['field']);
        $this->assertSame('greater_than', $result[1]['operator']);
        $this->assertSame(2010, $result[1]['value']);
        $this->assertSame('artist', $result[2]['field']);
        $this->assertSame('is_not_empty', $result[2]['operator']);
    }

    public function testParseThrowsOnNonArrayRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 must be an object');

        SmartPlaylist::parseRules(['not_an_array']);
    }

    public function testParseThrowsOnMissingFieldKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 is missing required key "field"');

        SmartPlaylist::parseRules([['operator' => 'equals']]);
    }

    public function testParseThrowsOnMissingOperatorKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 is missing required key "operator"');

        SmartPlaylist::parseRules([['field' => 'genre']]);
    }

    public function testParseThrowsOnEmptyField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 must have a non-empty string "field"');

        SmartPlaylist::parseRules([['field' => '', 'operator' => 'equals', 'value' => 'rock']]);
    }

    public function testParseThrowsOnWhitespaceField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 must have a non-empty string "field"');

        SmartPlaylist::parseRules([['field' => '  ', 'operator' => 'equals', 'value' => 'rock']]);
    }

    public function testParseThrowsOnNonStringField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmartPlaylist::parseRules([['field' => 123, 'operator' => 'equals', 'value' => 'rock']]);
    }

    public function testParseThrowsOnInvalidOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 has invalid operator "invalid_op"');

        SmartPlaylist::parseRules([['field' => 'genre', 'operator' => 'invalid_op', 'value' => 'rock']]);
    }

    public function testParseThrowsWhenValueRequiredButMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 requires a "value" key for operator "equals"');

        SmartPlaylist::parseRules([['field' => 'genre', 'operator' => 'equals']]);
    }

    public function testParseThrowsWhenNotEqualsRequiresValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 requires a "value" key for operator "not_equals"');

        SmartPlaylist::parseRules([['field' => 'genre', 'operator' => 'not_equals']]);
    }

    public function testParseThrowsWhenContainsRequiresValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 requires a "value" key for operator "contains"');

        SmartPlaylist::parseRules([['field' => 'title', 'operator' => 'contains']]);
    }

    public function testParseThrowsWhenGreaterThanRequiresValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 requires a "value" key for operator "greater_than"');

        SmartPlaylist::parseRules([['field' => 'year', 'operator' => 'greater_than']]);
    }

    public function testParseThrowsWhenLessThanRequiresValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 requires a "value" key for operator "less_than"');

        SmartPlaylist::parseRules([['field' => 'duration', 'operator' => 'less_than']]);
    }

    public function testParseReportsCorrectIndexOnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 2');

        SmartPlaylist::parseRules([
            ['field' => 'genre', 'operator' => 'is_empty'],
            ['field' => 'year', 'operator' => 'greater_than', 'value' => 2020],
            ['operator' => 'equals'], // Missing 'field' at index 2
        ]);
    }

    public function testParseWithNonStringOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule at index 0 has invalid operator');

        SmartPlaylist::parseRules([['field' => 'genre', 'operator' => 123]]);
    }

    public function testGetValidOperatorsReturnsAllOperators(): void
    {
        $operators = SmartPlaylist::getValidOperators();

        $this->assertSame([
            'equals',
            'not_equals',
            'contains',
            'greater_than',
            'less_than',
            'is_empty',
            'is_not_empty',
        ], $operators);
    }
}
