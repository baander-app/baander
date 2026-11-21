<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'userId' => new OA\Schema(
            description: 'Optional user public ID to get passkey options for',
            type: 'string',
            default: '',
        ),
    ],
    component: 'WebAuthnOptionsRequest',
)]
final readonly class WebAuthnOptionsRequest
{
    public function __construct(
        public string $userId = '',
    ) {
    }
}
