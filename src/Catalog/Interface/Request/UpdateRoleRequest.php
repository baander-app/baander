<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdateRoleRequest',
    required: ['role'],
    properties: [
        new OA\Property(property: 'role', description: 'Artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)', type: 'string'),
    ],
)]
final readonly class UpdateRoleRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Role is required.')]
        public string $role,
    ) {
    }
}
