<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\SystemSettingEntry;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SystemSettingResource',
    required: ['key', 'value', 'storedValue', 'isExplicit', 'storedValueValid'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'transcode.max_bitrate'),
        new OA\Property(property: 'value', description: 'Effective value', oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ]),
        new OA\Property(property: 'storedValue', description: 'Raw stored value, also when it is no longer allowed; null when unset', nullable: true),
        new OA\Property(property: 'isExplicit', type: 'boolean', description: 'Whether a value is stored rather than the default applying'),
        new OA\Property(property: 'storedValueValid', type: 'boolean', description: 'False when the stored value is no longer allowed and the default applies'),
    ],
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
