<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;
use App\Shared\Domain\Model\Setting\SupportedLanguages;

final class SharedSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string DEFAULT_LANGUAGE = 'i18n.default_language';
    public const string ADMIN_ALERTS = 'notifications.admin_alerts';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::DEFAULT_LANGUAGE,
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Default email language',
            description: 'Language of the emails sent to users who have not chosen one.',
            group: 'Language',
            default: SupportedLanguages::FALLBACK,
            allowedValues: SupportedLanguages::codes(),
            valueLabels: SupportedLanguages::NATIVE_NAMES,
            userVisible: true,
            enforced: false,
        );

        yield new SettingDefinition(
            key: self::ADMIN_ALERTS,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Admin alerts',
            description: 'Send alerts for critical system events (scan failures, health changes)',
            group: 'Notifications',
            default: true,
            enforced: false,
        );
    }
}
