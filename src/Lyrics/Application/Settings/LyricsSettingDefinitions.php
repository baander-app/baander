<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class LyricsSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string AUTO_FETCH = 'lyrics.auto_fetch';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::AUTO_FETCH,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Auto-fetch lyrics',
            description: 'Automatically fetch lyrics for new tracks',
            group: 'Content',
            default: false,
            enforced: false,
        );
    }
}
