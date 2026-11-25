<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use App\Playlist\Application\PlaylistLimits;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            maxLength: PlaylistLimits::NAME_LENGTH,
            example: 'My Playlist',
            default: '',
        ),
        'description' => new OA\Schema(
            type: 'string',
            nullable: true,
            maxLength: PlaylistLimits::DESCRIPTION_LENGTH,
            example: 'A great playlist',
            default: null,
        ),
        'isPublic' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'isCollaborative' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'isSmart' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'smartRules' => new OA\Schema(
            description: 'Rules for smart playlist auto-population',
            type: 'array',
            items: new OA\Schema(
                type: 'object',
                properties: [
                    'field' => new OA\Schema(
                        type: 'string',
                        enum: PlaylistLimits::SMART_RULE_FIELDS,
                    ),
                    'operator' => new OA\Schema(
                        type: 'string',
                        enum: PlaylistLimits::SMART_RULE_OPERATORS,
                    ),
                    'value' => new OA\Schema(
                        type: 'string',
                        maxLength: PlaylistLimits::SMART_RULE_VALUE_LENGTH,
                    ),
                ],
                required: ['field', 'operator'],
                additionalProperties: false,
            ),
            maxItems: PlaylistLimits::SMART_RULES,
            default: [],
        ),
    ],
    required: ['name'],
    component: 'CreatePlaylistRequest',
)]
final readonly class CreatePlaylistRequest
{
    /**
     * @param array<array-key, mixed> $smartRules
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required.')]
        #[Assert\Length(max: PlaylistLimits::NAME_LENGTH)]
        public string $name = '',
        #[Assert\Length(max: PlaylistLimits::DESCRIPTION_LENGTH)]
        public ?string $description = null,
        public bool $isPublic = false,
        public bool $isCollaborative = false,
        public bool $isSmart = false,
        #[Assert\Type('list')]
        #[Assert\Count(max: PlaylistLimits::SMART_RULES)]
        #[Assert\All([
            new Assert\Collection(
                fields: [
                    'field' => [
                        new Assert\NotNull(),
                        new Assert\Choice(choices: PlaylistLimits::SMART_RULE_FIELDS),
                    ],
                    'operator' => [
                        new Assert\NotNull(),
                        new Assert\Choice(choices: PlaylistLimits::SMART_RULE_OPERATORS),
                    ],
                    'value' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(max: PlaylistLimits::SMART_RULE_VALUE_LENGTH),
                    ]),
                ],
            ),
        ])]
        public array $smartRules = [],
    ) {
    }
}
