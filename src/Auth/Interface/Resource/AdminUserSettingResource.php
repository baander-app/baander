<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\UserPreference\Application\Port\UserSettingView;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'key' => new OA\Schema(type: 'string', example: 'language'),
        'label' => new OA\Schema(type: 'string', example: 'Language'),
        'type' => new OA\Schema(
            type: 'string',
            enum: ['boolean', 'integer', 'enum', 'string'],
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
        'userEditable' => new OA\Schema(description: 'Whether the user may change the setting themselves', type: 'boolean'),
        'storedValue' => new OA\Schema(
            description: "The user's choice as stored, also when it is no longer allowed; null when they have none",
            nullable: true,
        ),
        'storedValueValid' => new OA\Schema(
            description: 'False when the stored choice is no longer allowed, so the user gets the value after a reset',
            type: 'boolean',
        ),
        'value' => new OA\Schema(
            description: 'Effective value',
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
        ),
        'resetValue' => new OA\Schema(
            description: 'The value the setting would have after a reset',
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
        ),
        'source' => new OA\Schema(
            type: 'string',
            enum: ['user', 'server_default', 'default'],
        ),
    ],
    required: [
        'key',
        'label',
        'type',
        'options',
        'userEditable',
        'storedValue',
        'storedValueValid',
        'value',
        'resetValue',
        'source',
    ],
    component: 'AdminUserSettingResource',
)]
final class AdminUserSettingResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof UserSettingView);

        return [
            'key' => $source->key,
            'label' => $source->label,
            'type' => $source->type,
            'options' => $source->options,
            'userEditable' => $source->userEditable,
            'storedValue' => $source->storedValue,
            'storedValueValid' => $source->storedValueValid,
            'value' => $source->value,
            'resetValue' => $source->resetValue,
            'source' => $source->source,
        ];
    }
}
