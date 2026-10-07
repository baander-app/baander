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
            description: 'Sync each new album from external sources (MusicBrainz, Discogs) after a scan adds it',
            group: 'Content',
            default: false,
            enforced: true,
        );
    }
}
