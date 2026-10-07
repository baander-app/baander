<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class RecommendationSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string AUTO_GENERATE = 'recommendations.auto_generate';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::AUTO_GENERATE,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Auto-generate recommendations',
            description: 'Automatically generate recommendation snapshots',
            group: 'Content',
            default: false,
            enforced: true,
        );
    }
}
