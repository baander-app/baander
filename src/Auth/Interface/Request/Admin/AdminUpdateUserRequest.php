<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminUpdateUserRequest',
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'name', type: 'string', example: 'Alice'),
    ],
)]
final readonly class AdminUpdateUserRequest
{
    public function __construct(
        #[Assert\Email]
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
        public ?string $email = null,

        #[Assert\Length(min: 1, max: 255)]
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
        public ?string $name = null,
    ) {
    }
}
