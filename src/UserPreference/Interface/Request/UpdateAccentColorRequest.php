<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdateAccentColorRequest',
    required: ['color'],
    properties: [
        new OA\Property(property: 'color', type: 'string', example: 'violet'),
    ],
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
