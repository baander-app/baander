<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

#[OA\Schema(
    schema: 'CreatePartySessionRequest',
    required: ['videoId'],
    properties: [
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transcodeJobId', type: 'string', format: 'uuid', nullable: true, description: 'Transcode job of the video that the host\'s playback steers'),
        new OA\Property(property: 'maxMembers', type: 'integer', example: 10, maximum: 50, minimum: 2),
    ],
)]
final readonly class CreatePartySessionRequest
{
    public function __construct(
        #[NotBlank(message: 'Video ID is required.')]
        #[UuidConstraint]
        public string $videoId = '',
        #[NotBlank(allowNull: true)]
        #[UuidConstraint]
        public ?string $transcodeJobId = null,
        #[Range(min: 2, max: 50)]
        public int $maxMembers = 10,
    )
    {
    }
}
