<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'challengeKey' => new OA\Schema(
            description: 'Key returned by /api/auth/passkey/authenticate/options',
            type: 'string',
            maxLength: 255,
            example: 'challenge-key-abc123',
            default: '',
        ),
        'response' => new OA\Schema(
            description: 'WebAuthn assertion from navigator.credentials.get()',
            type: 'object',
            nullable: true,
            default: null,
        ),
    ],
    required: ['challengeKey', 'response'],
    component: 'VerifyPasskeyChallengeRequest',
)]
final readonly class VerifyPasskeyChallengeRequest
{
    public function __construct(
        #[NotBlank(message: 'challengeKey is required.')]
        #[Length(max: 255)]
        public string $challengeKey = '',

        #[NotBlank(message: 'response is required.')]
        public mixed $response = null,
    ) {
    }
}
