<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model\Setting;

use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    public function testEnumDefinitionAcceptsAllowedDefault(): void
    {
        $definition = $this->enum(default: 'da');

        $this->assertSame('da', $definition->default);
        $this->assertTrue($definition->allows('th'));
        $this->assertFalse($definition->allows('xx'));
    }

    public function testDefaultOutsideAllowedValuesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('i18n.default_language');

        $this->enum(default: 'xx');
    }

    public function testIntegerDefaultOutsideBoundsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingDefinition(
            key: 'scan.parallelism',
            type: SettingValueType::Integer,
            scope: SettingScope::System,
            label: 'Parallelism',
            description: 'Scanner workers.',
            group: 'Library',
            default: 12,
            min: 1,
            max: 8,
        );
    }

    public function testUserScopeDefinitionMayFollowSystemFallbackWithoutDefault(): void
    {
        $definition = new SettingDefinition(
            key: 'language',
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Email language',
            description: 'Language of the emails you receive.',
            group: 'Account',
            allowedValues: ['en', 'da', 'th'],
            fallbackKey: 'i18n.default_language',
            editRole: SettingDefinition::ROLE_USER,
        );

        $this->assertNull($definition->default);
        $this->assertSame('i18n.default_language', $definition->fallbackKey);
    }

    public function testDefinitionWithoutDefaultOrFallbackThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingDefinition(
            key: 'language',
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Email language',
            description: 'Language of the emails you receive.',
            group: 'Account',
            allowedValues: ['en', 'da', 'th'],
        );
    }

    public function testSystemScopeDefinitionCannotNameFallback(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingDefinition(
            key: 'i18n.default_language',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Default language',
            description: 'Server default.',
            group: 'Language',
            allowedValues: ['en', 'da'],
            fallbackKey: 'other.key',
        );
    }

    public function testUserVisibleFlagIsRejectedOnUserScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingDefinition(
            key: 'language',
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Email language',
            description: 'Language of the emails you receive.',
            group: 'Account',
            default: 'en',
            allowedValues: ['en', 'da'],
            userVisible: true,
        );
    }

    public function testEnumWithoutAllowedValuesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingDefinition(
            key: 'transcode.max_bitrate',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Max bitrate',
            description: 'Cap.',
            group: 'Transcoding',
            default: 320,
        );
    }

    public function testValueLabelsMustNameAllowedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->enum(default: 'en', valueLabels: ['de' => 'Deutsch']);
    }

    public function testAllowsIsStrictAboutTypes(): void
    {
        $boolean = new SettingDefinition(
            key: 'metadata.auto_sync',
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Auto sync',
            description: 'Sync after scans.',
            group: 'Metadata',
            default: true,
        );
        $bitrate = new SettingDefinition(
            key: 'transcode.max_bitrate',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Max bitrate',
            description: 'Cap.',
            group: 'Transcoding',
            default: 320,
            allowedValues: [128, 192, 256, 320],
        );

        $this->assertTrue($boolean->allows(false));
        $this->assertFalse($boolean->allows(1));
        $this->assertTrue($bitrate->allows(192));
        $this->assertFalse($bitrate->allows('192'));
    }

    /**
     * @param array<string, string> $valueLabels
     */
    private function enum(string $default, array $valueLabels = []): SettingDefinition
    {
        return new SettingDefinition(
            key: 'i18n.default_language',
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Default email language',
            description: 'Language for users without a choice.',
            group: 'Language',
            default: $default,
            allowedValues: ['en', 'da', 'th'],
            valueLabels: $valueLabels,
        );
    }
}
