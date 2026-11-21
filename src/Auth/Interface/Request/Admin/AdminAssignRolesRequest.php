<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'roles' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                enum: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
            ),
            example: ['ROLE_USER', 'ROLE_ADMIN'],
            default: [],
        ),
    ],
    required: ['roles'],
    component: 'AdminAssignRolesRequest',
)]
final readonly class AdminAssignRolesRequest
{
    /** @param array<array-key, string> $roles */
    public function __construct(
        #[Assert\NotBlank(message: 'Roles are required.')]
        #[Assert\Choice(choices: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], multiple: true)]
        public array $roles = [],
    ) {
    }
}
