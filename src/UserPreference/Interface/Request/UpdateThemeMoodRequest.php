<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdateThemeMoodRequest',
    required: ['mood'],
    properties: [
        new OA\Property(property: 'mood', type: 'string', example: 'dark'),
    ],
)]
final readonly class UpdateThemeMoodRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Mood is required.')]
        #[Assert\Choice(choices: ['dark', 'warm', 'cool', 'balanced'], message: 'Invalid mood. Choose from: dark, warm, cool, balanced.')]
        #[Assert\Length(max: 32)]
        public string $mood = '',
    ) {
    }
}
