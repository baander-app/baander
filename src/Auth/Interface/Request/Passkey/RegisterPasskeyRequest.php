<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Sequentially;

#[OA\Schema(
    type: 'object',
    properties: [
        'challengeKey' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'challenge-key-abc123',
            default: '',
        ),
        'response' => new OA\Schema(
            description: 'WebAuthn authenticator response (base64-encoded attestation object)',
            type: 'object',
            nullable: true,
            default: null,
        ),
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'Passkey',
            default: 'Passkey',
        ),
    ],
    required: ['challengeKey', 'response'],
    component: 'RegisterPasskeyRequest',
)]
final readonly class RegisterPasskeyRequest
{
    public function __construct(
        #[NotBlank(message: 'challengeKey is required.')]
        #[Length(max: 255)]
        public string $challengeKey = '',

        #[NotBlank(message: 'response is required.')]
        public mixed $response = null,

        #[Length(max: 255)]
        public string $name = 'Passkey',
    ) {
    }
}
