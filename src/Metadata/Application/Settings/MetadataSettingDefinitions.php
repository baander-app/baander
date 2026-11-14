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
            label: 'Automatic MusicBrainz matching',
            description: 'Match each album a scan adds or changes against MusicBrainz, apply confident matches to empty fields, IDs and credits, and leave the rest for review',
            group: 'Content',
            default: false,
            enforced: true,
        );
    }
}
