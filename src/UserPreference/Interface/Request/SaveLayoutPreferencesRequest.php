<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'payload' => new OA\Schema(
            type: 'object',
            properties: [
                'mode' => new OA\Schema(
                    type: 'string',
                    enum: SaveLayoutPreferencesRequest::MODES,
                    example: 'expanded',
                ),
                'activeTab' => new OA\Schema(
                    type: 'string',
                    enum: SaveLayoutPreferencesRequest::TABS,
                    example: 'queue',
                ),
            ],
            required: ['mode', 'activeTab'],
            additionalProperties: false,
        ),
        'version' => new OA\Schema(
            type: 'integer',
            minimum: 0,
            example: 0,
        ),
    ],
    required: ['payload', 'version'],
    component: 'SaveLayoutPreferencesRequest',
)]
final readonly class SaveLayoutPreferencesRequest
{
    public const array MODES = ['compact', 'expanded'];
    public const array TABS = ['queue', 'lyrics', 'details', 'info'];

    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array')]
        #[Assert\Collection(
            fields: [
                'mode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: self::MODES)],
                'activeTab' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: self::TABS)],
            ],
            allowMissingFields: false,
        )]
        public array $payload = [],

        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
