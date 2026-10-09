<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdateRoleRequest',
    required: ['role'],
    properties: [
        new OA\Property(property: 'role', description: 'The new artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)', type: 'string'),
        new OA\Property(property: 'currentRole', description: 'The role of the credit to change; required when the artist holds several roles on the song or album', type: 'string', nullable: true),
    ],
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
