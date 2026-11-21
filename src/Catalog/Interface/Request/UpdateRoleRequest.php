<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'role' => new OA\Schema(
            description: 'The new artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)',
            type: 'string',
        ),
        'currentRole' => new OA\Schema(
            description: 'The role of the credit to change; required when the artist holds several roles on the song or album',
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['role'],
    component: 'UpdateRoleRequest',
)]
final readonly class UpdateRoleRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Role is required.')]
        public string $role,
        public ?string $currentRole = null,
    ) {
    }
}
