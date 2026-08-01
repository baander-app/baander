<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SavePlayerPreferencesRequest',
    required: ['payload', 'version'],
    properties: [
        new OA\Property(
            property: 'payload',
            properties: [
                new OA\Property(property: 'shuffle', type: 'boolean', example: false),
                new OA\Property(property: 'repeat', type: 'string', example: 'off', enum: ['off', 'all', 'one']),
                new OA\Property(property: 'volume', type: 'number', example: 0.8, minimum: 0, maximum: 1),
                new OA\Property(property: 'muted', type: 'boolean', example: false),
                new OA\Property(property: 'crossfadeEnabled', type: 'boolean', example: false),
                new OA\Property(property: 'crossfadeDuration', type: 'number', example: 5.0, minimum: 0, maximum: 12),
                new OA\Property(property: 'replayGainEnabled', type: 'boolean', example: false),
                new OA\Property(property: 'replayGainMode', type: 'string', example: 'track', enum: ['track', 'album']),
                new OA\Property(property: 'replayGainPreAmp', type: 'number', example: 0.0, minimum: -15, maximum: 15),
            ],
        ),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, example: 0),
    ],
)]
final readonly class SavePlayerPreferencesRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array')]
        #[Assert\Collection(
            fields: [
                'shuffle' => [new Assert\NotNull(), new Assert\Type('bool')],
                'repeat' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['off', 'all', 'one'])],
                'volume' => [new Assert\NotNull(), new Assert\Type('numeric'), new Assert\Range(min: 0, max: 1)],
                'muted' => [new Assert\NotNull(), new Assert\Type('bool')],
                'crossfadeEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'crossfadeDuration' => [new Assert\NotNull(), new Assert\Type('numeric'), new Assert\Range(min: 0, max: 12)],
                'replayGainEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'replayGainMode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['track', 'album'])],
                'replayGainPreAmp' => [new Assert\NotNull(), new Assert\Type('numeric'), new Assert\Range(min: -15, max: 15)],
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
