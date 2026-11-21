<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'targetPublicId' => new OA\Schema(
            description: 'Public ID of the target album (the one to keep)',
            type: 'string',
            minLength: 1,
            maxLength: 50,
            default: '',
        ),
        'sourcePublicId' => new OA\Schema(
            description: 'Public ID of the source album (the one to merge into target)',
            type: 'string',
            minLength: 1,
            maxLength: 50,
            default: '',
        ),
    ],
    required: ['targetPublicId', 'sourcePublicId'],
    component: 'MergeAlbumsRequest',
)]
final readonly class MergeAlbumsRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Target public ID is required.')]
        #[Assert\Length(min: 1, max: 50)]
        public string $targetPublicId = '',

        #[Assert\NotBlank(message: 'Source public ID is required.')]
        #[Assert\Length(min: 1, max: 50)]
        public string $sourcePublicId = '',
    ) {
    }
}
