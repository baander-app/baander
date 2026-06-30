<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'MergeAlbumsRequest',
    required: ['targetPublicId', 'sourcePublicId'],
    properties: [
        new OA\Property(property: 'targetPublicId', description: 'Public ID of the target album (the one to keep)', type: 'string'),
        new OA\Property(property: 'sourcePublicId', description: 'Public ID of the source album (the one to merge into target)', type: 'string'),
    ],
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
