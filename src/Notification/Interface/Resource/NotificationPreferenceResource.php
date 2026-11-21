<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\NotificationPreference;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    properties: [
        'category' => new OA\Schema(
            description: 'Notification category',
            type: 'string',
            enum: ['security', 'background_jobs', 'media_changes', 'admin_operations'],
        ),
        'channel' => new OA\Schema(
            description: 'Notification channel',
            type: 'string',
            enum: ['in_app', 'email', 'push', 'webhook'],
        ),
        'enabled' => new OA\Schema(description: 'Whether this channel is enabled', type: 'boolean'),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    component: 'NotificationPreferenceResource',
)]
final class NotificationPreferenceResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof NotificationPreference);

        return [
            'category' => $source->getCategory()->value,
            'channel' => $source->getChannel()->value,
            'enabled' => $source->isEnabled(),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

}
