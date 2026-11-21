<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'settings' => new OA\Schema(
            description: 'Setting key to new value',
            type: 'object',
            additionalProperties: true,
        ),
    ],
    required: ['settings'],
    component: 'UpdateSystemSettingsRequest',
)]
final readonly class UpdateSystemSettingsRequest
{
    /**
     * @param array<array-key, mixed> $settings setting key to raw value; validated against the definitions
     */
    public function __construct(
        #[Assert\NotNull]
        public array $settings,
    ) {
    }
}
