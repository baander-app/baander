<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'VerifyPasskeyChallengeRequest',
    required: ['challengeKey', 'response'],
    properties: [
        new OA\Property(property: 'challengeKey', type: 'string', example: 'challenge-key-abc123', maxLength: 255),
        new OA\Property(property: 'response', description: 'WebAuthn authenticator response (base64-encoded assertion)', type: 'object'),
        new OA\Property(property: 'userId', type: 'string', nullable: true, maxLength: 255),
    ],
)]
final readonly class VerifyPasskeyChallengeRequest
{
    public function __construct(
        #[NotBlank(message: 'challengeKey is required.')]
        #[Length(max: 255)]
        public string $challengeKey = '',

        #[NotBlank(message: 'response is required.')]
        public mixed $response = null,

        #[Length(max: 255)]
        public string $userId = '',
    ) {
    }
}
