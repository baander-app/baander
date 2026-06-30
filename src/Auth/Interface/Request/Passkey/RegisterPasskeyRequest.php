<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Sequentially;

#[OA\Schema(
    schema: 'RegisterPasskeyRequest',
    required: ['challengeKey', 'response'],
    properties: [
        new OA\Property(property: 'challengeKey', type: 'string', example: 'challenge-key-abc123', maxLength: 255),
        new OA\Property(property: 'response', description: 'WebAuthn authenticator response (base64-encoded attestation object)', type: 'object'),
        new OA\Property(property: 'name', type: 'string', example: 'Passkey', maxLength: 255),
    ],
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
