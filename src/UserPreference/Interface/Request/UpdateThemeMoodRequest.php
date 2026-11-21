<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'mood' => new OA\Schema(
            type: 'string',
            maxLength: 32,
            enum: ['dark', 'warm', 'cool', 'balanced'],
            example: 'dark',
            default: '',
        ),
    ],
    required: ['mood'],
    component: 'UpdateThemeMoodRequest',
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
