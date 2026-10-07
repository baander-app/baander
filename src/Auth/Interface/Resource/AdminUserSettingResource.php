<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\UserPreference\Application\Port\UserSettingView;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminUserSettingResource',
    required: ['key', 'label', 'type', 'options', 'userEditable', 'storedValue', 'storedValueValid', 'value', 'resetValue', 'source'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'language'),
        new OA\Property(property: 'label', type: 'string', example: 'Email language'),
        new OA\Property(property: 'type', type: 'string', enum: ['boolean', 'integer', 'enum', 'string']),
        new OA\Property(property: 'options', description: 'Allowed values of an enum setting, with their labels', type: 'array', items: new OA\Items(
            required: ['value', 'label'],
            properties: [
                new OA\Property(property: 'value', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')]),
                new OA\Property(property: 'label', type: 'string'),
            ],
        )),
        new OA\Property(property: 'userEditable', type: 'boolean', description: 'Whether the user may change the setting themselves'),
        new OA\Property(property: 'storedValue', description: "The user's choice as stored, also when it is no longer allowed; null when they have none", nullable: true),
        new OA\Property(property: 'storedValueValid', type: 'boolean', description: 'False when the stored choice is no longer allowed, so the user gets the value after a reset'),
        new OA\Property(property: 'value', description: 'Effective value', oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ]),
        new OA\Property(property: 'resetValue', description: 'The value the setting would have after a reset', oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ]),
        new OA\Property(property: 'source', type: 'string', enum: ['user', 'server_default', 'default']),
    ],
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
