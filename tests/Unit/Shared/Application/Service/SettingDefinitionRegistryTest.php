<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Service;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionRegistryTest extends TestCase
{
    public function testCollectsDefinitionsFromEveryProvider(): void
    {
        $registry = new SettingDefinitionRegistry([
            $this->provider($this->systemLanguage()),
            $this->provider($this->userLanguage()),
        ]);

        $this->assertSame('i18n.default_language', $registry->get('i18n.default_language')?->key);
        $this->assertSame(['language'], array_map(
            static fn (SettingDefinition $definition): string => $definition->key,
            $registry->forScope(SettingScope::User),
        ));
        $this->assertCount(2, $registry->all());
    }

    public function testUnknownKeyReturnsNothing(): void
    {
        $registry = new SettingDefinitionRegistry([$this->provider($this->systemLanguage())]);

        $this->assertNull($registry->get('unknown.key'));
    }

    public function testDuplicateKeyFailsNamingTheKey(): void
    {
        $registry = new SettingDefinitionRegistry([
            $this->provider($this->systemLanguage()),
            $this->provider($this->systemLanguage()),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('i18n.default_language');

        $registry->all();
    }

    public function testFallbackToMissingSystemDefinitionFails(): void
    {
        $registry = new SettingDefinitionRegistry([$this->provider($this->userLanguage())]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('i18n.default_language');

        $registry->get('language');
    }

    public function testFallbackToUserScopeDefinitionFails(): void
    {
        $other = new SettingDefinition(
            key: 'i18n.default_language',
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Other',
            description: 'Other.',
            group: 'Account',
            default: 'en',
            allowedValues: ['en', 'da', 'th'],
        );
        $registry = new SettingDefinitionRegistry([$this->provider($other, $this->userLanguage())]);

        $this->expectException(LogicException::class);

        $registry->all();
    }

    private function systemLanguage(): SettingDefinition
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
            userVisible: true,
        );
    }

    private function userLanguage(): SettingDefinition
    {
        return new SettingDefinition(
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
    }

    private function provider(SettingDefinition ...$definitions): SettingDefinitionProviderInterface
    {
        return new readonly class ($definitions) implements SettingDefinitionProviderInterface {
            /**
             * @param list<SettingDefinition> $definitions
             */
            public function __construct(private array $definitions)
            {
            }

            public function definitions(): iterable
            {
                return $this->definitions;
            }
        };
    }
}
