<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'stationId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
        ),
    ],
    required: ['stationId'],
    component: 'StarStationRequest',
)]
final readonly class StarStationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(exactly: PublicId::LENGTH)]
        #[Assert\Regex(pattern: PublicId::REGEX)]
        public string $stationId,
    ) {
    }
}
