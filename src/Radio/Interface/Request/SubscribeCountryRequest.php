<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'sourceId' => new OA\Schema(
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            default: null,
        ),
        'countryCode' => new OA\Schema(
            type: 'string',
            minLength: 2,
            maxLength: 2,
            default: '',
        ),
    ],
    component: 'SubscribeCountryRequest',
)]
final readonly class SubscribeCountryRequest
{
    public function __construct(
        #[Assert\Length(exactly: PublicId::LENGTH)]
        #[Assert\Regex(pattern: PublicId::REGEX)]
        public ?string $sourceId = null,

        #[Assert\NotBlank]
        #[Assert\Length(exactly: 2)]
        public string $countryCode = '',
    ) {
    }
}
