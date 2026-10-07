<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Interface\Resource\SettingDefinitionResource;
use App\UserPreference\Application\DTO\UserSettingEntry;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserSettingResource',
    required: ['key', 'choice', 'value', 'resetValue', 'source', 'editable', 'definition'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'language'),
        new OA\Property(property: 'choice', description: "The user's explicit choice; null when they have none", nullable: true, oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ]),
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
        new OA\Property(property: 'editable', type: 'boolean', description: 'Whether the user may change the setting themselves'),
        new OA\Property(property: 'definition', ref: new Model(type: SettingDefinitionResource::class)),
    ],
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
