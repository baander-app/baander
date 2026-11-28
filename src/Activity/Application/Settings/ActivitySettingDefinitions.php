<?php

declare(strict_types=1);

namespace App\Activity\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class ActivitySettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string PROGRESS_CLOCK_SKEW_SECONDS = 'activity.progress_clock_skew_seconds';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::PROGRESS_CLOCK_SKEW_SECONDS,
            type: SettingValueType::Integer,
            scope: SettingScope::System,
            label: 'Video progress clock tolerance',
            description: 'How many seconds older a video progress report may be than the saved observation when updating a resume position.',
            group: 'Media',
            default: 0,
            min: 0,
        );
    }
}
