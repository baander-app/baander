<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;

#[OA\Schema(
    schema: 'CreatePairingCodeRequest',
    required: ['serverPublicId', 'method'],
    properties: [
        new OA\Property(property: 'serverPublicId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'method', type: 'string', enum: ['qr_code', 'email_url', 'server_code']),
    ],
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
