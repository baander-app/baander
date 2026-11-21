<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'color' => new OA\Schema(
            type: 'string',
            maxLength: 32,
            example: 'violet',
            default: '',
        ),
    ],
    required: ['color'],
    component: 'UpdateAccentColorRequest',
)]
final readonly class UpdateAccentColorRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Color is required.')]
        #[Assert\Length(max: 32)]
        public string $color = '',
    ) {
    }
}
