<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\NotificationPreference;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationPreferenceResource',
    properties: [
        new OA\Property(property: 'category', description: 'Notification category', type: 'string', enum: ['security', 'background_jobs', 'media_changes', 'admin_operations']),
        new OA\Property(property: 'channel', description: 'Notification channel', type: 'string', enum: ['in_app', 'email', 'push', 'webhook']),
        new OA\Property(property: 'enabled', description: 'Whether this channel is enabled', type: 'boolean'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
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
