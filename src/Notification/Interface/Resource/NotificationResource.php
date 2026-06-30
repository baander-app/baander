<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\Notification;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'category', type: 'string', description: 'Notification category'),
        new OA\Property(property: 'eventType', type: 'string', description: 'Event type'),
        new OA\Property(property: 'title', type: 'string', description: 'Notification title'),
        new OA\Property(property: 'body', type: 'string', description: 'Notification body'),
        new OA\Property(property: 'parameters', type: 'object', description: 'Notification parameters'),
        new OA\Property(property: 'isRead', type: 'boolean', description: 'Whether the notification has been read'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class NotificationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Notification);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'category' => $source->getCategory()->value,
            'eventType' => $source->getEventType(),
            'title' => $source->getTitle(),
            'body' => $source->getBody(),
            'parameters' => $source->getParameters(),
            'isRead' => $source->isRead(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
