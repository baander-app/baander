<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    schema: 'TransferHostRequest',
    required: ['newHostUserId'],
    properties: [
        new OA\Property(property: 'newHostUserId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
    ],
)]
final readonly class TransferHostRequest
{
    public function __construct(
        #[NotBlank]
        #[Regex(pattern: '/^[A-Za-z0-9_-]{21}$/D')]
        public string $newHostUserId = '',
    ) {
    }
}
