<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    required: ['sections'],
    properties: [
        new OA\Property(
            property: 'sections',
            type: 'array',
            items: new OA\Schema(
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'music-quick-jump'),
                    new OA\Property(property: 'label', type: 'string', example: 'Quick Jump'),
                    new OA\Property(property: 'type', type: 'string', example: 'navigation'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Schema(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'music-home'),
                                new OA\Property(property: 'type', type: 'string', example: 'page_link', enum: ['page_link', 'smart_filter', 'panel_action']),
                                new OA\Property(property: 'label', type: 'string', example: 'Home'),
                                new OA\Property(property: 'icon', type: 'string', example: 'home'),
                                new OA\Property(property: 'config', type: 'object'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
    ],
)]
final readonly class UpdateSidebarSectionsRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Sections are required.')]
        #[Assert\Type(type: 'array')]
        public array $sections = [],
    ) {
    }
}
