<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'DeviceApproveRequest',
    required: ['userCode', 'action'],
    properties: [
        new OA\Property(property: 'userCode', type: 'string', example: 'ABCD-EFGH', maxLength: 255),
        new OA\Property(property: 'action', type: 'string', example: 'approve', enum: ['approve', 'deny']),
    ],
)]
final readonly class DeviceApproveRequest
{
    public function __construct(
        #[NotBlank(message: 'User code is required.')]
        #[Length(max: 255)]
        public string $userCode = '',

        #[NotBlank(message: 'Action is required.')]
        #[Choice(choices: ['approve', 'deny'], message: 'The "action" parameter must be "approve" or "deny".')]
        public string $action = '',
    ) {
    }
}
