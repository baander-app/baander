<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    type: 'object',
    properties: [
        'newHostUserId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: '',
        ),
    ],
    required: ['newHostUserId'],
    component: 'TransferHostRequest',
)]
final readonly class TransferHostRequest
{
    public function __construct(
        #[NotBlank]
        #[Regex(pattern: PublicId::REGEX)]
        public string $newHostUserId = '',
    ) {
    }
}
