<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use App\Session\Interface\Resource\PlaybackMediaResource;
use App\Session\Interface\Validator\PlaybackCheckpoint;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'incarnationId' => new OA\Schema(type: 'string'),
        'tabId' => new OA\Schema(type: 'string'),
        'recoveryCredential' => new OA\Schema(type: 'string'),
        'checkpoint' => new OA\Schema(
            type: 'object',
            properties: [
                'media' => new OA\Schema(type: 'object', nullable: true, allOf: [new OA\Schema(ref: PlaybackMediaResource::class)]),
                'revision' => new OA\Schema(type: 'integer', minimum: 0),
                'currentTrackIndex' => new OA\Schema(type: 'integer', minimum: 0),
                'position' => new OA\Schema(type: 'number', format: 'float', minimum: 0),
            ],
            required: ['media', 'revision', 'currentTrackIndex', 'position'],
        ),
    ],
    required: ['incarnationId', 'tabId', 'recoveryCredential', 'checkpoint'],
    component: 'RecoverLocalPlaybackRequest',
)]
final readonly class RecoverLocalPlaybackRequest
{
    /** @param array{revision:int,media:?array{kind:string,publicId:string,viewing?:array{titleKind:string,titleId:string}},currentTrackIndex:int,position:float} $checkpoint */
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
