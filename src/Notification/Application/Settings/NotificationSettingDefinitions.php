<?php

declare(strict_types=1);

namespace App\Notification\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class NotificationSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string PUSH_ENABLED = 'notifications.push_enabled';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::PUSH_ENABLED,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Push notifications',
            description: 'Enable browser push notifications',
            group: 'Notifications',
            default: true,
            enforced: false,
        );
    }
}
