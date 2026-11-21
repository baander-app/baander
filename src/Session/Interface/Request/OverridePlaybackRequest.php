<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'requestId' => new OA\Schema(type: 'string'),
        'expectedRevision' => new OA\Schema(type: 'integer', minimum: 0),
        'incarnationId' => new OA\Schema(type: 'string'),
    ],
    required: ['requestId', 'expectedRevision', 'incarnationId'],
    component: 'OverridePlaybackRequest',
)]
final readonly class OverridePlaybackRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $incarnationId,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
