<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(type: 'string'),
        'type' => new OA\Schema(type: 'string'),
        'syncUrl' => new OA\Schema(type: 'string'),
        'syncConfig' => new OA\Schema(
            type: 'object',
            additionalProperties: new OA\Schema(nullable: true),
            default: new \stdClass(),
        ),
        'syncSchedule' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['name', 'type', 'syncUrl'],
    component: 'CreateRadioSourceRequest',
)]
final readonly class CreateRadioSourceRequest
{
    /** @param array<string, mixed> $syncConfig */
    public function __construct(
        #[Assert\NotBlank]
        public string $name,

        #[Assert\NotBlank]
        public string $type,

        #[Assert\NotBlank]
        #[Assert\Url]
        public string $syncUrl,

        #[Assert\Type('array')]
        public array $syncConfig = [],

        public ?string $syncSchedule = null,
    ) {
    }
}
