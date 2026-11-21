<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'userCode' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'ABCD-EFGH',
            default: '',
        ),
        'decision' => new OA\Schema(
            type: 'string',
            enum: ['approve', 'deny'],
            example: 'approve',
        ),
    ],
    required: ['userCode', 'decision'],
    component: 'DeviceApproveRequest',
)]
final readonly class DeviceApproveRequest
{
    public function __construct(
        #[NotBlank(message: 'User code is required.')]
        #[Length(max: 255)]
        public string $userCode = '',

        #[NotBlank(message: 'Decision is required.')]
        #[Choice(choices: ['approve', 'deny'], message: 'The decision must be "approve" or "deny".')]
        public string $decision = '',
    ) {
    }
}
