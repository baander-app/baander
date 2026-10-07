<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Service;

use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingValueParserTest extends TestCase
{
    private SettingValueParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SettingValueParser();
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function validBooleans(): iterable
    {
        yield 'cli true' => ['true', true];
        yield 'cli false' => ['false', false];
        yield 'json true' => [true, true];
        yield 'json false' => [false, false];
    }

    #[DataProvider('validBooleans')]
    public function testBooleanAcceptsLiteralAndStringForms(mixed $input, bool $expected): void
    {
        $result = $this->parser->parse($this->boolean(), $input);

        $this->assertTrue($result->isValid());
        $this->assertSame($expected, $result->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBooleans(): iterable
    {
        yield 'yes' => ['yes'];
        yield 'integer one' => [1];
        yield 'null' => [null];
    }

    #[DataProvider('invalidBooleans')]
    public function testBooleanRejectsOtherValues(mixed $input): void
    {
        $result = $this->parser->parse($this->boolean(), $input);

        $this->assertFalse($result->isValid());
        $this->assertSame('metadata.auto_sync', $result->violation?->key);
    }

    public function testStringEnumAcceptsAllowedValue(): void
    {
        $result = $this->parser->parse($this->language(), 'da');

        $this->assertTrue($result->isValid());
        $this->assertSame('da', $result->value);
    }

    public function testStringEnumRejectsUnknownValueNamingTheKey(): void
    {
        $result = $this->parser->parse($this->language(), 'xx');

        $this->assertNotNull($result->violation);
        $this->assertSame('i18n.default_language', $result->violation->key);
        $this->assertStringContainsString('en, da, th', $result->violation->message);
    }

    public function testIntegerEnumAcceptsCliStringAndJsonInteger(): void
    {
        $fromCli = $this->parser->parse($this->bitrate(), '192');
        $fromJson = $this->parser->parse($this->bitrate(), 256);

        $this->assertSame(192, $fromCli->value);
        $this->assertSame(256, $fromJson->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBitrates(): iterable
    {
        yield 'not allowed' => [999];
        yield 'non numeric' => ['abc'];
        yield 'float' => [192.0];
    }

    #[DataProvider('invalidBitrates')]
    public function testIntegerEnumRejectsInvalidValues(mixed $input): void
    {
        $result = $this->parser->parse($this->bitrate(), $input);

        $this->assertFalse($result->isValid());
        $this->assertSame('transcode.max_bitrate', $result->violation?->key);
    }

    public function testBoundedIntegerAcceptsValueInsideBounds(): void
    {
        $result = $this->parser->parse($this->parallelism(), '4');

        $this->assertSame(4, $result->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIntegers(): iterable
    {
        yield 'below minimum' => [0];
        yield 'above maximum' => ['9'];
        yield 'non numeric string' => ['four'];
        yield 'decimal string' => ['4.5'];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalidIntegers')]
    public function testBoundedIntegerRejectsValuesOutsideBoundsAndNonNumbers(mixed $input): void
    {
        $result = $this->parser->parse($this->parallelism(), $input);

        $this->assertFalse($result->isValid());
        $this->assertSame('scan.parallelism', $result->violation?->key);
    }

    public function testStringAcceptsTextAndRejectsOtherTypes(): void
    {
        $definition = new SettingDefinition(
            key: 'branding.name',
            type: SettingValueType::String,
            scope: SettingScope::System,
            label: 'Server name',
            description: 'Shown in emails.',
            group: 'General',
            default: 'Baander',
        );

        $this->assertSame('Home', $this->parser->parse($definition, 'Home')->value);
        $this->assertFalse($this->parser->parse($definition, 12)->isValid());
    }

    private function boolean(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'metadata.auto_sync',
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Auto sync',
            description: 'Sync after scans.',
            group: 'Metadata',
            default: true,
        );
    }

    private function language(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'i18n.default_language',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Default email language',
            description: 'Language for users without a choice.',
            group: 'Language',
            default: 'en',
            allowedValues: ['en', 'da', 'th'],
        );
    }

    private function bitrate(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'transcode.max_bitrate',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Max bitrate',
            description: 'Cap.',
            group: 'Transcoding',
            default: 320,
            allowedValues: [128, 192, 256, 320],
        );
    }

    private function parallelism(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'scan.parallelism',
            type: SettingValueType::Integer,
            scope: SettingScope::System,
            label: 'Parallelism',
            description: 'Scanner workers.',
            group: 'Library',
            default: 2,
            min: 1,
            max: 8,
        );
    }
}
