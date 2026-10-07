<?php

declare(strict_types=1);

namespace App\Metadata\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class MetadataSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string AUTO_SYNC = 'metadata.auto_sync';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::AUTO_SYNC,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Auto-sync metadata',
            description: 'Automatically sync metadata from external sources (Discogs, MusicBrainz)',
            group: 'Content',
            default: false,
            enforced: false,
        );
    }
}
