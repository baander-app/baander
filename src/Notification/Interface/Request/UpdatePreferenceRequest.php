<?php

declare(strict_types=1);

namespace App\Notification\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdatePreferenceRequest',
    required: ['preferences'],
    properties: [
        new OA\Property(
            property: 'preferences',
            type: 'array',
            items: new OA\Schema(
                required: ['category', 'channel', 'enabled'],
                properties: [
                    new OA\Property(property: 'category', type: 'string', enum: ['security',
                                                                                 'background_jobs',
                                                                                 'media_changes']),
                    new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email', 'push', 'webhook']),
                    new OA\Property(property: 'enabled', type: 'boolean'),
                ],
            ),
        ),
    ],
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
                    new Assert\NotBlank(),
                    new Assert\Type('bool'),
                ],
            ]),
        ])]
        public array $preferences = [],
    )
    {
    }
}
