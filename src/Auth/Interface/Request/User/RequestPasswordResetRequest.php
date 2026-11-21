<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;

#[OA\Schema(
    type: 'object',
    properties: [
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            example: 'alice@example.com',
            default: '',
        ),
    ],
    required: ['email'],
    component: 'RequestPasswordResetRequest',
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
