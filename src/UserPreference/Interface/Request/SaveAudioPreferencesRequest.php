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
                'enabled' => new OA\Schema(type: 'boolean'),
                'bands' => new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(
                        type: 'object',
                        properties: [
                            'gain' => new OA\Schema(
                                type: 'number',
                                minimum: -12,
                                maximum: 12,
                            ),
                            'q' => new OA\Schema(
                                type: 'number',
                                minimum: 0.1,
                                maximum: 10,
                            ),
                        ],
                        required: ['gain', 'q'],
                        additionalProperties: false,
                    ),
                    minItems: 10,
                    maxItems: 10,
                ),
                'preset' => new OA\Schema(
                    type: 'string',
                    enum: ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS'],
                ),
                'compressionEnabled' => new OA\Schema(type: 'boolean'),
                'compressorThreshold' => new OA\Schema(
                    type: 'number',
                    minimum: -50,
                    maximum: 0,
                ),
                'compressorRatio' => new OA\Schema(
                    type: 'number',
                    minimum: 1,
                    maximum: 20,
                ),
                'compressorKnee' => new OA\Schema(
                    type: 'number',
                    minimum: 0,
                    maximum: 40,
                ),
                'compressorAttack' => new OA\Schema(
                    type: 'number',
                    minimum: 0.1,
                    maximum: 100,
                ),
                'compressorRelease' => new OA\Schema(
                    type: 'number',
                    minimum: 10,
                    maximum: 1000,
                ),
                'masterGain' => new OA\Schema(
                    type: 'number',
                    minimum: -12,
                    maximum: 12,
                ),
                'normalizationEnabled' => new OA\Schema(type: 'boolean'),
                'targetLufs' => new OA\Schema(type: 'number'),
                'visualizerMode' => new OA\Schema(
                    type: 'string',
                    enum: ['enhanced-spectrum', 'circular', 'spectrogram', 'particles', 'spectrum', 'meters', 'phase'],
                ),
                'stereoEnabled' => new OA\Schema(type: 'boolean'),
                'stereoWidth' => new OA\Schema(
                    type: 'number',
                    minimum: 0,
                    maximum: 2,
                ),
                'stereoMode' => new OA\Schema(
                    type: 'string',
                    enum: ['normal', 'mid', 'side'],
                ),
                'crossfeedEnabled' => new OA\Schema(type: 'boolean'),
                'crossfeedPreset' => new OA\Schema(
                    type: 'string',
                    enum: ['light', 'normal', 'heavy'],
                ),
                'loudnessContourEnabled' => new OA\Schema(type: 'boolean'),
                'chainOrder' => new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(
                        type: 'string',
                        enum: ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain'],
                    ),
                    minItems: 6,
                    maxItems: 6,
                    uniqueItems: true,
                ),
            ],
            required: [
                'enabled',
                'bands',
                'preset',
                'compressionEnabled',
                'compressorThreshold',
                'compressorRatio',
                'compressorKnee',
                'compressorAttack',
                'compressorRelease',
                'masterGain',
                'normalizationEnabled',
                'targetLufs',
                'visualizerMode',
                'stereoEnabled',
                'stereoWidth',
                'stereoMode',
                'crossfeedEnabled',
                'crossfeedPreset',
                'loudnessContourEnabled',
                'chainOrder',
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
    component: 'SaveAudioPreferencesRequest',
)]
final readonly class SaveAudioPreferencesRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
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

        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Version must be at least 0.')]
        public int $version = 0,
    ) {
    }
}
