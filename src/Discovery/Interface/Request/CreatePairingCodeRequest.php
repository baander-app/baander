<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;

#[OA\Schema(
    type: 'object',
    properties: [
        'serverPublicId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: '',
        ),
        'method' => new OA\Schema(
            type: 'string',
            enum: ['qr_code', 'email_url', 'server_code'],
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
