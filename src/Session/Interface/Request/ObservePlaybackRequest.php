<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'incarnationId' => new OA\Schema(type: 'string'),
        'expectedRevision' => new OA\Schema(type: 'integer', minimum: 0),
        'observedState' => new OA\Schema(
            type: 'string',
            enum: ['playing', 'paused', 'stopped', 'unknown'],
        ),
        'position' => new OA\Schema(
            type: 'number',
            format: 'float',
            minimum: 0,
        ),
        'volume' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            maximum: 1,
            default: null,
        ),
    ],
    required: ['incarnationId', 'expectedRevision', 'observedState', 'position'],
    component: 'ObservePlaybackRequest',
)]
final readonly class ObservePlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Choice(choices: ['playing', 'paused', 'stopped', 'unknown'])]
        public string $observedState,
        #[Assert\PositiveOrZero]
        public float $position,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
