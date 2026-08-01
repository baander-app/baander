<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminAssignRolesRequest',
    required: ['roles'],
    properties: [
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_USER', 'ROLE_ADMIN']),
    ],
)]
final readonly class AdminAssignRolesRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Roles are required.')]
        #[Assert\Choice(choices: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], multiple: true)]
        public array $roles = [],
    ) {
    }
}
