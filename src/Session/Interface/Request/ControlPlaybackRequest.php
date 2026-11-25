<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'requestId' => new OA\Schema(type: 'string'),
        'expectedRevision' => new OA\Schema(type: 'integer', minimum: 0),
        'action' => new OA\Schema(
            type: 'string',
            enum: ['play', 'pause', 'seek', 'load', 'stop', 'volume', 'queue'],
        ),
        'position' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            default: null,
        ),
        'media' => new OA\Schema(
            type: 'object',
            nullable: true,
            allOf: [
                new OA\Schema(
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\Schema(type: 'string'),
                ),
                new OA\Schema(
                    type: 'object',
                    nullable: true,
                    properties: [
                        'kind' => new OA\Schema(
                            type: 'string',
                            enum: ['song', 'video'],
                        ),
                    ],
                ),
            ],
            default: null,
        ),
        'queue' => new OA\Schema(
            type: 'array',
            nullable: true,
            items: new OA\Schema(
                type: 'string',
                minLength: PublicId::LENGTH,
                maxLength: PublicId::LENGTH,
                pattern: PublicId::SCHEMA_PATTERN,
            ),
            maxItems: ControlPlaybackRequest::MAX_QUEUE,
            default: null,
        ),
        'currentTrackIndex' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 0,
            default: null,
        ),
        'desiredState' => new OA\Schema(
            type: 'string',
            nullable: true,
            enum: ['playing', 'paused', 'stopped', null],
            default: null,
        ),
        'volume' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            maximum: 1,
            default: null,
        ),
        'incarnationId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['requestId', 'expectedRevision', 'action'],
    component: 'ControlPlaybackRequest',
)]
final readonly class ControlPlaybackRequest
{
    public const int MAX_QUEUE = 10000;

    /**
     * @param array{kind:string,publicId:string}|null $media
     * @param list<string>|null $queue
     */
    public function __construct(
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Choice(choices: ['play', 'pause', 'seek', 'load', 'stop', 'volume', 'queue'])]
        public string $action,
        #[Assert\PositiveOrZero]
        public ?float $position = null,
        #[Assert\Collection(fields: ['kind' => new Assert\Choice(choices: ['song', 'video']), 'publicId' => new Assert\Regex(pattern: PublicId::REGEX)])]
        public ?array $media = null,
        #[Assert\Type('list')]
        #[Assert\Count(max: self::MAX_QUEUE)]
        #[Assert\All(constraints: [new Assert\Regex(pattern: PublicId::REGEX)])]
        public ?array $queue = null,
        #[Assert\PositiveOrZero]
        public ?int $currentTrackIndex = null,
        #[Assert\Choice(choices: ['playing', 'paused', 'stopped'])]
        public ?string $desiredState = null,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
        #[Assert\Uuid]
        public ?string $incarnationId = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
