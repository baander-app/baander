<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\NotificationPreference;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationPreferenceResource',
    properties: [
        new OA\Property(property: 'category', type: 'string', enum: ['security', 'background_jobs', 'media_changes', 'admin_operations'], description: 'Notification category'),
        new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email', 'push', 'webhook'], description: 'Notification channel'),
        new OA\Property(property: 'enabled', type: 'boolean', description: 'Whether this channel is enabled'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
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
