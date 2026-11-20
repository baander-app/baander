<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(schema: 'SaveAudioPreferencesRequest', required: ['payload', 'version'], properties: [
    new OA\Property(
        property: 'payload',
        required: ['enabled', 'bands', 'preset', 'compressionEnabled', 'compressorThreshold', 'compressorRatio', 'compressorKnee', 'compressorAttack', 'compressorRelease', 'masterGain', 'normalizationEnabled', 'targetLufs', 'visualizerMode', 'stereoEnabled', 'stereoWidth', 'stereoMode', 'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled', 'chainOrder'],
        properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(
                property: 'bands',
                type: 'array',
                items: new OA\Items(
                    required: ['gain', 'q'],
                    properties: [
                        new OA\Property(property: 'gain', type: 'number', maximum: 12, minimum: -12),
                        new OA\Property(property: 'q', type: 'number', maximum: 10, minimum: 0.1),
                    ],
                    type: 'object',
                    additionalProperties: false,
                ),
                maxItems: 10,
                minItems: 10,
            ),
            new OA\Property(property: 'preset', type: 'string', enum: ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS']),
            new OA\Property(property: 'compressionEnabled', type: 'boolean'),
            new OA\Property(property: 'compressorThreshold', type: 'number', maximum: 0, minimum: -50),
            new OA\Property(property: 'compressorRatio', type: 'number', maximum: 20, minimum: 1),
            new OA\Property(property: 'compressorKnee', type: 'number', maximum: 40, minimum: 0),
            new OA\Property(property: 'compressorAttack', type: 'number', maximum: 100, minimum: 0.1),
            new OA\Property(property: 'compressorRelease', type: 'number', maximum: 1000, minimum: 10),
            new OA\Property(property: 'masterGain', type: 'number', maximum: 12, minimum: -12),
            new OA\Property(property: 'normalizationEnabled', type: 'boolean'),
            new OA\Property(property: 'targetLufs', type: 'number'),
            new OA\Property(property: 'visualizerMode', type: 'string', enum: ['enhanced-spectrum', 'circular', 'spectrogram', 'particles', 'spectrum', 'meters', 'phase']),
            new OA\Property(property: 'stereoEnabled', type: 'boolean'),
            new OA\Property(property: 'stereoWidth', type: 'number', maximum: 2, minimum: 0),
            new OA\Property(property: 'stereoMode', type: 'string', enum: ['normal', 'mid', 'side']),
            new OA\Property(property: 'crossfeedEnabled', type: 'boolean'),
            new OA\Property(property: 'crossfeedPreset', type: 'string', enum: ['light', 'normal', 'heavy']),
            new OA\Property(property: 'loudnessContourEnabled', type: 'boolean'),
            new OA\Property(property: 'chainOrder', type: 'array', items: new OA\Items(type: 'string', enum: ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain']), maxItems: 6, minItems: 6, uniqueItems: true),
        ],
        type: 'object',
        additionalProperties: false,
    ),
    new OA\Property(property: 'version', type: 'integer', example: 0, minimum: 0),
], type: 'object')]
final readonly class SaveAudioPreferencesRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Ignore]
        #[Assert\NotNull(message: 'Payload is required.')]
        #[Assert\Type(type: 'array', message: 'Payload must be an object.')]
        #[Assert\Collection(fields: [
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
                            allowExtraFields: false,
                            allowMissingFields: false,
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
            'targetLufs' => [new Assert\NotNull(), new Assert\Type('finite-number')],
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
        ], allowExtraFields: false, allowMissingFields: false)]
        public array $payload = [],

        #[Ignore]
        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
