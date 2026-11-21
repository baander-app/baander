<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\SystemSettingEntry;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'key' => new OA\Schema(type: 'string', example: 'transcode.max_bitrate'),
        'value' => new OA\Schema(
            description: 'Effective value',
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
        ),
        'storedValue' => new OA\Schema(
            description: 'Raw stored value, also when it is no longer allowed; null when unset',
            nullable: true,
        ),
        'isExplicit' => new OA\Schema(
            description: 'Whether a value is stored rather than the default applying',
            type: 'boolean',
        ),
        'storedValueValid' => new OA\Schema(
            description: 'False when the stored value is no longer allowed and the default applies',
            type: 'boolean',
        ),
    ],
    required: ['key', 'value', 'storedValue', 'isExplicit', 'storedValueValid'],
    component: 'SystemSettingResource',
)]
final class SystemSettingResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof SystemSettingEntry);

        return [
            'key' => $source->definition->key,
            'value' => $source->value,
            'storedValue' => $source->storedValue,
            'isExplicit' => $source->isExplicit(),
            'storedValueValid' => $source->storedValueValid,
        ];
    }
}
