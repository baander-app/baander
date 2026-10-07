<?php

declare(strict_types=1);

namespace App\Transcode\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class TranscodeSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string ENABLED = 'transcode.enabled';
    public const string MAX_BITRATE = 'transcode.max_bitrate';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::ENABLED,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Enable transcoding',
            description: 'Allow on-the-fly transcoding of audio tracks',
            group: 'Media',
            default: false,
        );

        yield new SettingDefinition(
            key: self::MAX_BITRATE,
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'Max transcode bitrate',
            description: 'Maximum bitrate for transcoded audio streams',
            group: 'Media',
            default: 320,
            allowedValues: [128, 192, 256, 320],
            valueLabels: [128 => '128 kbps', 192 => '192 kbps', 256 => '256 kbps', 320 => '320 kbps'],
        );
    }
}
