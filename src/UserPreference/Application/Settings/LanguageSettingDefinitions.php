<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;
use App\Shared\Domain\Model\Setting\SupportedLanguages;

final class LanguageSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string LANGUAGE = 'language';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::LANGUAGE,
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Email language',
            description: 'The language of the emails Baander sends you.',
            group: 'Account',
            allowedValues: SupportedLanguages::codes(),
            valueLabels: SupportedLanguages::NATIVE_NAMES,
            editRole: SettingDefinition::ROLE_USER,
            fallbackKey: SharedSettingDefinitions::DEFAULT_LANGUAGE,
        );
    }
}
