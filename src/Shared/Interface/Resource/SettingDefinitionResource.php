<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Domain\Model\Setting\SettingDefinition;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SettingDefinitionResource',
    required: ['key', 'type', 'scope', 'label', 'description', 'group', 'default', 'options', 'min', 'max', 'editRole', 'userVisible', 'enforced', 'fallbackKey'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'transcode.max_bitrate'),
        new OA\Property(property: 'type', type: 'string', enum: ['boolean', 'integer', 'enum', 'string']),
        new OA\Property(property: 'scope', type: 'string', enum: ['system', 'user']),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'group', type: 'string', description: 'Group label the settings page shows the setting under'),
        new OA\Property(property: 'default', description: 'Default value; null when the setting follows fallbackKey', nullable: true, oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ]),
        new OA\Property(property: 'options', description: 'Allowed values of an enum setting, with their labels', type: 'array', items: new OA\Items(
            required: ['value', 'label'],
            properties: [
                new OA\Property(property: 'value', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')]),
                new OA\Property(property: 'label', type: 'string'),
            ],
        )),
        new OA\Property(property: 'min', type: 'integer', nullable: true),
        new OA\Property(property: 'max', type: 'integer', nullable: true),
        new OA\Property(property: 'editRole', type: 'string', enum: ['ROLE_USER', 'ROLE_SUPER_ADMIN']),
        new OA\Property(property: 'userVisible', type: 'boolean', description: 'Whether user settings may follow this system setting, which shows its value to signed-in users as their default'),
        new OA\Property(property: 'enforced', type: 'boolean', description: 'False while the backend does not yet honour the setting'),
        new OA\Property(property: 'fallbackKey', type: 'string', nullable: true, description: 'System setting a user setting follows when unset'),
    ],
)]
final class SettingDefinitionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof SettingDefinition);

        return [
            'key' => $source->key,
            'type' => $source->type->value,
            'scope' => $source->scope->value,
            'label' => $source->label,
            'description' => $source->description,
            'group' => $source->group,
            'default' => $source->default,
            'options' => $source->options(),
            'min' => $source->min,
            'max' => $source->max,
            'editRole' => $source->editRole,
            'userVisible' => $source->userVisible,
            'enforced' => $source->enforced,
            'fallbackKey' => $source->fallbackKey,
        ];
    }
}
