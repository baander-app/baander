<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'ChangeEmailRequest',
    required: ['email'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
    ],
)]
final readonly class ChangeEmailRequest
{
    public function __construct(
        #[NotBlank(message: 'Email is required.')]
        #[EmailConstraint(message: 'Please provide a valid email address.')]
        public string $email = '',
    ) {
    }
}
