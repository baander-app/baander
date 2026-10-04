<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SaveLayoutPreferencesRequest',
    required: ['payload', 'version'],
    properties: [
        new OA\Property(
            property: 'payload',
            type: 'object',
            required: ['mode', 'activeTab'],
            properties: [
                new OA\Property(property: 'mode', type: 'string', example: 'expanded', enum: SaveLayoutPreferencesRequest::MODES),
                new OA\Property(property: 'activeTab', type: 'string', example: 'queue', enum: SaveLayoutPreferencesRequest::TABS),
            ],
            additionalProperties: false,
        ),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, example: 0),
    ],
)]
final readonly class SaveLayoutPreferencesRequest
{
    public const array MODES = ['compact', 'expanded'];
    public const array TABS = ['queue', 'lyrics', 'details', 'info'];

    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Ignore]
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

        #[Ignore]
        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
