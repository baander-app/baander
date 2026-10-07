<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateSystemSettingsRequest
{
    /**
     * @param array<array-key, mixed> $settings setting key to raw value; validated against the definitions
     */
    public function __construct(
        #[Assert\NotNull]
        #[OA\Property(description: 'Setting key to new value', type: 'object', additionalProperties: true)]
        public array $settings,
    ) {
    }
}
