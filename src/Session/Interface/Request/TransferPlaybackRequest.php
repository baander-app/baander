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
        'targetIncarnationId' => new OA\Schema(type: 'string'),
    ],
    required: ['requestId', 'expectedRevision', 'targetIncarnationId'],
    component: 'TransferPlaybackRequest',
)]
final readonly class TransferPlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Uuid]
        public string $targetIncarnationId,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
