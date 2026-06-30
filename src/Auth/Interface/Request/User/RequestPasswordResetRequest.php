<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;

#[OA\Schema(
    schema: 'RequestPasswordResetRequest',
    required: ['email'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
    ],
)]
final readonly class RequestPasswordResetRequest
{
    public function __construct(
        #[NotBlank(message: 'Email is required.')]
        #[Email]
        public string $email = '',
    ) {
    }
}
