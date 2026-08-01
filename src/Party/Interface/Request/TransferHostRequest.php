<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

#[OA\Schema(
    schema: 'TransferHostRequest',
    required: ['newHostUserId'],
    properties: [
        new OA\Property(property: 'newHostUserId', type: 'string', format: 'uuid'),
    ],
)]
final readonly class TransferHostRequest
{
    public function __construct(
        #[NotBlank]
        #[UuidConstraint]
        public string $newHostUserId = '',
    ) {
    }
}
