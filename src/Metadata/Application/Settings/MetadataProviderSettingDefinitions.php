<?php

declare(strict_types=1);

namespace App\Metadata\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class MetadataProviderSettingDefinitions implements SettingDefinitionProviderInterface
{
    public function definitions(): iterable
    {
        foreach (MetadataProvider::cases() as $provider) {
            yield new SettingDefinition(
                key: $provider->settingKey(),
                type: SettingValueType::Boolean,
                scope: SettingScope::System,
                label: $provider->label(),
                description: 'Use ' . $provider->label() . ' for metadata lookups.',
                group: 'Metadata providers',
                default: true,
            );
        }
    }
}
