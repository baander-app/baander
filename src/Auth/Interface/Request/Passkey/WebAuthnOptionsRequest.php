<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WebAuthnOptionsRequest',
    properties: [
        new OA\Property(property: 'userId', description: 'Optional user ID to get passkey options for', type: 'string'),
    ],
)]
final readonly class WebAuthnOptionsRequest
{
    public function __construct(
        public string $userId = '',
    ) {
    }
}
