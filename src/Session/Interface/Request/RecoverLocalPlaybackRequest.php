<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use App\Session\Interface\Validator\PlaybackCheckpoint;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'incarnationId' => new OA\Schema(type: 'string'),
        'tabId' => new OA\Schema(type: 'string'),
        'recoveryCredential' => new OA\Schema(type: 'string'),
        'checkpoint' => new OA\Schema(
            allOf: [
                new OA\Schema(
                    type: 'object',
                    additionalProperties: new OA\Schema(
                        oneOf: [
                            new OA\Schema(
                                type: 'object',
                                additionalProperties: new OA\Schema(type: 'string'),
                            ),
                            new OA\Schema(type: 'number', format: 'float'),
                            new OA\Schema(type: 'integer'),
                            new OA\Schema(
                                type: 'object',
                                nullable: true,
                                enum: [null],
                            ),
                        ],
                    ),
                ),
                new OA\Schema(
                    type: 'object',
                    properties: [
                        'media' => new OA\Schema(
                            oneOf: [
                                new OA\Schema(
                                    allOf: [
                                        new OA\Schema(
                                            type: 'object',
                                            additionalProperties: new OA\Schema(type: 'string'),
                                        ),
                                        new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'kind' => new OA\Schema(
                                                    type: 'string',
                                                    enum: ['song', 'video'],
                                                ),
                                            ],
                                        ),
                                    ],
                                ),
                                new OA\Schema(type: 'number', format: 'float'),
                                new OA\Schema(type: 'integer'),
                                new OA\Schema(
                                    type: 'object',
                                    nullable: true,
                                    enum: [null],
                                ),
                            ],
                        ),
                    ],
                ),
            ],
        ),
    ],
    required: ['incarnationId', 'tabId', 'recoveryCredential', 'checkpoint'],
    component: 'RecoverLocalPlaybackRequest',
)]
final readonly class RecoverLocalPlaybackRequest
{
    /** @param array{revision:int,media:?array{kind:string,publicId:string},currentTrackIndex:int,position:float} $checkpoint */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $tabId,
        #[Assert\Regex(pattern: '/^[0-9a-f]{64}$/D')]
        #[\SensitiveParameter]
        public string $recoveryCredential,
        #[PlaybackCheckpoint]
        public array $checkpoint,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
