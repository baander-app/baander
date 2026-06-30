<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SaveAudioPreferencesRequest',
    required: ['payload', 'version'],
    properties: [
        new OA\Property(
            property: 'payload',
            properties: [
                new OA\Property(property: 'schemaVersion', type: 'integer', example: 2),
                new OA\Property(property: 'eqMode', type: 'string', example: 'simple'),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                new OA\Property(property: 'bands', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'gain', type: 'number', minimum: -12, maximum: 12),
                    new OA\Property(property: 'q', type: 'number', minimum: 0.1, maximum: 10),
                ])),
                new OA\Property(property: 'preset', type: 'string', example: 'FLAT'),
                new OA\Property(property: 'peqPoints', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'frequency', type: 'number'),
                    new OA\Property(property: 'gain', type: 'number'),
                    new OA\Property(property: 'q', type: 'number'),
                    new OA\Property(property: 'type', type: 'string'),
                ])),
                new OA\Property(property: 'compressor', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                    new OA\Property(property: 'threshold', type: 'number'),
                    new OA\Property(property: 'ratio', type: 'number'),
                    new OA\Property(property: 'knee', type: 'number'),
                    new OA\Property(property: 'attack', type: 'number'),
                    new OA\Property(property: 'release', type: 'number'),
                ]),
                new OA\Property(property: 'masterGain', type: 'number', example: 0.0),
                new OA\Property(property: 'normalization', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                    new OA\Property(property: 'targetLufs', type: 'number'),
                ]),
                new OA\Property(property: 'stereo', properties: [
                    new OA\Property(property: 'width', type: 'number'),
                    new OA\Property(property: 'mode', type: 'string'),
                ]),
                new OA\Property(property: 'crossfeed', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'preset', type: 'string'),
                ]),
                new OA\Property(property: 'loudnessContour', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                ]),
                new OA\Property(property: 'chainOrder', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'compareSlots', properties: [
                    new OA\Property(property: 'a', type: 'object', nullable: true),
                    new OA\Property(property: 'b', type: 'object', nullable: true),
                ]),
                new OA\Property(property: 'activeProfileId', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, example: 0),
    ],
)]
final readonly class SaveAudioPreferencesRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array', message: 'Payload must be an object.')]
        public array $payload = [],

        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
