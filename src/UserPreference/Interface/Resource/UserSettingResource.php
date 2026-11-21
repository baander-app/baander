<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Interface\Resource\SettingDefinitionResource;
use App\UserPreference\Application\DTO\UserSettingEntry;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'key' => new OA\Schema(type: 'string', example: 'language'),
        'choice' => new OA\Schema(
            description: "The user's explicit choice; null when they have none",
            nullable: true,
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
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
        'editable' => new OA\Schema(description: 'Whether the user may change the setting themselves', type: 'boolean'),
        'definition' => new OA\Schema(ref: SettingDefinitionResource::class),
    ],
    required: ['key', 'choice', 'value', 'resetValue', 'source', 'editable', 'definition'],
    component: 'UserSettingResource',
)]
final class UserSettingResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof UserSettingEntry);

        return [
            'key' => $source->definition->key,
            'choice' => $source->choice,
            'value' => $source->value,
            'resetValue' => $source->resetValue,
            'source' => $source->source->value,
            'editable' => $source->definition->isUserEditable(),
            'definition' => SettingDefinitionResource::from($source->definition),
        ];
    }
}
