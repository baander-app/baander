<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SaveAudioPreferencesRequest',
    type: 'object',
    required: ['payload', 'version'],
    properties: [
        new OA\Property(
            property: 'payload',
            type: 'object',
            required: ['enabled', 'bands', 'preset', 'compressionEnabled', 'compressorThreshold', 'compressorRatio', 'compressorKnee', 'compressorAttack', 'compressorRelease', 'masterGain', 'normalizationEnabled', 'targetLufs', 'visualizerMode', 'stereoEnabled', 'stereoWidth', 'stereoMode', 'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled', 'chainOrder'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(
                    property: 'bands',
                    type: 'array',
                    minItems: 10,
                    maxItems: 10,
                    items: new OA\Items(
                        type: 'object',
                        required: ['gain', 'q'],
                        properties: [
                            new OA\Property(property: 'gain', type: 'number', minimum: -12, maximum: 12),
                            new OA\Property(property: 'q', type: 'number', minimum: 0.1, maximum: 10),
                        ],
                        additionalProperties: false,
                    ),
                ),
                new OA\Property(property: 'preset', type: 'string', enum: ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS']),
                new OA\Property(property: 'compressionEnabled', type: 'boolean'),
                new OA\Property(property: 'compressorThreshold', type: 'number', minimum: -50, maximum: 0),
                new OA\Property(property: 'compressorRatio', type: 'number', minimum: 1, maximum: 20),
                new OA\Property(property: 'compressorKnee', type: 'number', minimum: 0, maximum: 40),
                new OA\Property(property: 'compressorAttack', type: 'number', minimum: 0.1, maximum: 100),
                new OA\Property(property: 'compressorRelease', type: 'number', minimum: 10, maximum: 1000),
                new OA\Property(property: 'masterGain', type: 'number', minimum: -12, maximum: 12),
                new OA\Property(property: 'normalizationEnabled', type: 'boolean'),
                new OA\Property(property: 'targetLufs', type: 'number', enum: [-14, -16, -18, -23]),
                new OA\Property(property: 'visualizerMode', type: 'string', enum: ['enhanced-spectrum', 'circular', 'spectrogram', 'particles', 'spectrum', 'meters', 'phase']),
                new OA\Property(property: 'stereoEnabled', type: 'boolean'),
                new OA\Property(property: 'stereoWidth', type: 'number', minimum: 0, maximum: 2),
                new OA\Property(property: 'stereoMode', type: 'string', enum: ['normal', 'mid', 'side']),
                new OA\Property(property: 'crossfeedEnabled', type: 'boolean'),
                new OA\Property(property: 'crossfeedPreset', type: 'string', enum: ['light', 'normal', 'heavy']),
                new OA\Property(property: 'loudnessContourEnabled', type: 'boolean'),
                new OA\Property(property: 'chainOrder', type: 'array', minItems: 6, maxItems: 6, uniqueItems: true, items: new OA\Items(type: 'string', enum: ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain'])),
            ],
            additionalProperties: false,
        ),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, example: 0),
    ],
)]
final readonly class SaveAudioPreferencesRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Ignore]
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array', message: 'Payload must be an object.')]
        #[Assert\Collection(
            fields: [
                'enabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'bands' => new Assert\Sequentially([
                    new Assert\NotNull(),
                    new Assert\Type('list'),
                    new Assert\Count(exactly: 10),
                    new Assert\All([
                        new Assert\Sequentially([
                            new Assert\NotNull(),
                            new Assert\Type('associative_array'),
                            new Assert\Collection(
                                fields: [
                                    'gain' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: -12, max: 12)],
                                    'q' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0.1, max: 10)],
                                ],
                                allowMissingFields: false,
                                allowExtraFields: false,
                            ),
                        ]),
                    ]),
                ]),
                'preset' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS'])],
                'compressionEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'compressorThreshold' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: -50, max: 0)],
                'compressorRatio' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 1, max: 20)],
                'compressorKnee' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 40)],
                'compressorAttack' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0.1, max: 100)],
                'compressorRelease' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 10, max: 1000)],
                'masterGain' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: -12, max: 12)],
                'normalizationEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'targetLufs' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Choice(choices: [-14, -14.0, -16, -16.0, -18, -18.0, -23, -23.0])],
                'visualizerMode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['enhanced-spectrum', 'circular', 'spectrogram', 'particles', 'spectrum', 'meters', 'phase'])],
                'stereoEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'stereoWidth' => [new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 2)],
                'stereoMode' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['normal', 'mid', 'side'])],
                'crossfeedEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'crossfeedPreset' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['light', 'normal', 'heavy'])],
                'loudnessContourEnabled' => [new Assert\NotNull(), new Assert\Type('bool')],
                'chainOrder' => new Assert\Sequentially([
                    new Assert\NotNull(),
                    new Assert\Type('list'),
                    new Assert\Count(exactly: 6),
                    new Assert\Unique(),
                    new Assert\All([new Assert\NotNull(), new Assert\Type('string'), new Assert\Choice(choices: ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain'])]),
                ]),
            ],
            allowMissingFields: false,
            allowExtraFields: false,
        )]
        public array $payload = [],

        #[Ignore]
        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
