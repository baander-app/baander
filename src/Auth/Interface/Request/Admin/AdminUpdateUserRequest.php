<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use App\Auth\Application\CommandHandler\User\RenameUserHandler;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            nullable: true,
            example: 'user@example.com',
            default: null,
        ),
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            maxLength: 255,
            example: 'Alice',
            default: null,
        ),
    ],
    component: 'AdminUpdateUserRequest',
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
