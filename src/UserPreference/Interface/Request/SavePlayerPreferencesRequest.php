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
                'shuffle' => new OA\Schema(type: 'boolean', example: false),
                'repeat' => new OA\Schema(
                    type: 'string',
                    enum: ['off', 'all', 'one'],
                    example: 'off',
                ),
                'volume' => new OA\Schema(
                    type: 'number',
                    minimum: 0,
                    maximum: 1,
                    example: 0.8,
                ),
                'muted' => new OA\Schema(type: 'boolean', example: false),
                'crossfadeEnabled' => new OA\Schema(type: 'boolean', example: false),
                'crossfadeDuration' => new OA\Schema(
                    type: 'number',
                    minimum: 0,
                    maximum: 12,
                    example: 5.0,
                ),
                'replayGainEnabled' => new OA\Schema(type: 'boolean', example: false),
                'replayGainMode' => new OA\Schema(
                    type: 'string',
                    enum: ['track', 'album'],
                    example: 'track',
                ),
                'replayGainPreAmp' => new OA\Schema(
                    type: 'number',
                    minimum: -15,
                    maximum: 15,
                    example: 0.0,
                ),
            ],
            required: [
                'shuffle',
                'repeat',
                'volume',
                'muted',
                'crossfadeEnabled',
                'crossfadeDuration',
                'replayGainEnabled',
                'replayGainMode',
                'replayGainPreAmp',
            ],
            additionalProperties: false,
        ),
        'version' => new OA\Schema(
            type: 'integer',
            minimum: 0,
            example: 0,
        ),
    ],
    required: ['payload', 'version'],
    component: 'SavePlayerPreferencesRequest',
)]
final readonly class SavePlayerPreferencesRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array')]
        #[Assert\Collection(
            fields: [
                'shuffle' => [new Assert\NotNull(), new Assert\Type('bool')],
                'repeat' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['off', 'all', 'one'])],
                'volume' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 1)],
                'muted' => [new Assert\NotNull(), new Assert\Type('bool')],
                'crossfadeEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'crossfadeDuration' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 12)],
                'replayGainEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'replayGainMode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['track', 'album'])],
                'replayGainPreAmp' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: -15, max: 15)],
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
