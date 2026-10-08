<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use App\Auth\Application\CommandHandler\User\RenameUserHandler;
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

        // The rename handler's rule and messages, so `app:user:rename` rejects the same names alike.
        #[Assert\Length(max: RenameUserHandler::NAME_MAX_LENGTH, maxMessage: RenameUserHandler::NAME_TOO_LONG)]
        #[Assert\NotBlank(message: RenameUserHandler::BLANK_NAME, allowNull: true, normalizer: 'trim')]
        public ?string $name = null,
    ) {
    }
}
