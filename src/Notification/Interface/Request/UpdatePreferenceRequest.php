<?php

declare(strict_types=1);

namespace App\Notification\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'preferences' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                properties: [
                    'category' => new OA\Schema(
                        type: 'string',
                        enum: ['security', 'background_jobs', 'media_changes'],
                    ),
                    'channel' => new OA\Schema(
                        type: 'string',
                        enum: ['in_app', 'email', 'push', 'webhook'],
                    ),
                    'enabled' => new OA\Schema(type: 'boolean'),
                ],
                required: ['category', 'channel', 'enabled'],
            ),
            default: [],
        ),
    ],
    required: ['preferences'],
    component: 'UpdatePreferenceRequest',
)]
final readonly class UpdatePreferenceRequest
{
    /**
     * @param list<array{category: string, channel: string, enabled: bool}> $preferences
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Preferences are required.')]
        #[Assert\Type(type: 'array')]
        #[Assert\All([
            new Assert\Collection([
                'category' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(choices: ['security', 'background_jobs', 'media_changes']),
                ],
                'channel'  => [
                    new Assert\NotBlank(),
                    new Assert\Choice(choices: ['in_app', 'email', 'push', 'webhook']),
                ],
                'enabled'  => [
                    new Assert\NotNull(),
                    new Assert\Type('bool'),
                ],
            ]),
        ])]
        public array $preferences = [],
    )
    {
    }
}
