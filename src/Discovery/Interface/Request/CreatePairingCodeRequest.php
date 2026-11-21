<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;

#[OA\Schema(
    type: 'object',
    properties: [
        'serverPublicId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: '',
        ),
        'method' => new OA\Schema(
            type: 'string',
            enum: ['qr_code', 'email_url', 'server_code'],
            default: '',
        ),
    ],
    required: ['serverPublicId', 'method'],
    component: 'CreatePairingCodeRequest',
)]
final readonly class CreatePairingCodeRequest
{
    public function __construct(
        #[NotBlank(message: 'Server public ID is required.')]
        public string $serverPublicId = '',
        #[NotBlank(message: 'Authentication method is required.')]
        #[Choice(choices: ['qr_code', 'email_url', 'server_code'], message: 'Invalid authentication method.')]
        public string $method = '',
    ) {
    }
}
