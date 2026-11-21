<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'sections' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'id' => new OA\Schema(type: 'string', example: 'music-quick-jump'),
                    'label' => new OA\Schema(type: 'string', example: 'Quick Jump'),
                    'type' => new OA\Schema(type: 'string', example: 'navigation'),
                    'items' => new OA\Schema(
                        type: 'array',
                        items: new OA\Schema(
                            properties: [
                                'id' => new OA\Schema(type: 'string', example: 'music-home'),
                                'type' => new OA\Schema(
                                    type: 'string',
                                    enum: ['page_link', 'smart_filter', 'panel_action'],
                                    example: 'page_link',
                                ),
                                'label' => new OA\Schema(type: 'string', example: 'Home'),
                                'icon' => new OA\Schema(type: 'string', example: 'home'),
                                'config' => new OA\Schema(type: 'object'),
                            ],
                        ),
                    ),
                ],
            ),
            default: [],
        ),
    ],
    required: ['sections'],
    component: 'UpdateSidebarSectionsRequest',
)]
final readonly class UpdateSidebarSectionsRequest
{
    /** @param array<array-key, mixed> $sections */
    public function __construct(
        #[Assert\NotBlank(message: 'Sections are required.')]
        #[Assert\Type(type: 'array')]
        public array $sections = [],
    ) {
    }
}
