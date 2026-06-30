<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SaveLayoutPreferencesRequest',
    required: ['payload', 'version'],
    properties: [
        new OA\Property(
            property: 'payload',
            properties: [
                new OA\Property(property: 'mode', type: 'string', example: 'expanded', enum: ['compact', 'expanded', 'pioneer']),
                new OA\Property(property: 'activeTab', type: 'string', example: 'library'),
            ],
        ),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, example: 0),
    ],
)]
final readonly class SaveLayoutPreferencesRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array')]
        #[Assert\Collection(
            fields: [
                'mode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['compact', 'expanded', 'pioneer'])],
                'activeTab' => [new Assert\NotNull(), new Assert\Type('string')],
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
