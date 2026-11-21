<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Domain\Model\Setting\SettingDefinition;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'key' => new OA\Schema(type: 'string', example: 'transcode.max_bitrate'),
        'type' => new OA\Schema(
            type: 'string',
            enum: ['boolean', 'integer', 'enum', 'string'],
        ),
        'scope' => new OA\Schema(
            type: 'string',
            enum: ['system', 'user'],
        ),
        'label' => new OA\Schema(type: 'string'),
        'description' => new OA\Schema(type: 'string'),
        'group' => new OA\Schema(description: 'Group label the settings page shows the setting under', type: 'string'),
        'default' => new OA\Schema(
            description: 'Default value; null when the setting follows fallbackKey',
            nullable: true,
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
        ),
        'options' => new OA\Schema(
            description: 'Allowed values of an enum setting, with their labels',
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'value' => new OA\Schema(
                        oneOf: [
                            new OA\Schema(type: 'integer'),
                            new OA\Schema(type: 'string'),
                        ],
                    ),
                    'label' => new OA\Schema(type: 'string'),
                ],
                required: ['value', 'label'],
            ),
        ),
        'min' => new OA\Schema(type: 'integer', nullable: true),
        'max' => new OA\Schema(type: 'integer', nullable: true),
        'editRole' => new OA\Schema(
            type: 'string',
            enum: ['ROLE_USER', 'ROLE_SUPER_ADMIN'],
        ),
        'userVisible' => new OA\Schema(
            description: 'Whether user settings may follow this system setting, which shows its value to signed-in users as their default',
            type: 'boolean',
        ),
        'enforced' => new OA\Schema(
            description: 'False while the backend does not yet honour the setting',
            type: 'boolean',
        ),
        'fallbackKey' => new OA\Schema(
            description: 'System setting a user setting follows when unset',
            type: 'string',
            nullable: true,
        ),
    ],
    required: [
        'key',
        'type',
        'scope',
        'label',
        'description',
        'group',
        'default',
        'options',
        'min',
        'max',
        'editRole',
        'userVisible',
        'enforced',
        'fallbackKey',
    ],
    component: 'SettingDefinitionResource',
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
