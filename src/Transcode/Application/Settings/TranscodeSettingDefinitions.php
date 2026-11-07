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
    public const string AAC_IMPLEMENTATION = 'transcode.aac_implementation';

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

        // The values stay literal here: this class may not import the Transcode
        // Domain enum that maps them to FFmpeg codec names.
        yield new SettingDefinition(
            key: self::AAC_IMPLEMENTATION,
            type: SettingValueType::Enum,
            scope: SettingScope::System,
            label: 'AAC implementation',
            description: 'Which codec implementation FFmpeg uses to encode AAC renditions and decode AAC sources for them',
            group: 'Media',
            default: 'baac',
            allowedValues: ['baac', 'fdk_aac', 'ffmpeg'],
            valueLabels: ['baac' => 'bAAC', 'fdk_aac' => 'Fraunhofer fdk-aac', 'ffmpeg' => 'FFmpeg built-in'],
        );
    }
}
