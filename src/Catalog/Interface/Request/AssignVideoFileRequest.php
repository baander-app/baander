<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Catalog\Interface\Resource\VideoMatchChoicesResource;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'episodePublicIds' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
        'extraKind' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchChoicesResource::EXTRA_KINDS),
    ],
    component: 'AssignVideoFileRequest',
)]
final readonly class AssignVideoFileRequest
{
    /** @param list<string> $episodePublicIds */
    public function __construct(
        #[Assert\All([new Assert\Type('string')])]
        public array $episodePublicIds = [],
        public ?string $extraKind = null,
    ) {}
}
