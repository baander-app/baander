<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'sourceId' => new OA\Schema(
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
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
        #[Assert\Length(exactly: 21)]
        #[Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/D')]
        public ?string $sourceId = null,

        #[Assert\NotBlank]
        #[Assert\Length(exactly: 2)]
        public string $countryCode = '',
    ) {
    }
}
