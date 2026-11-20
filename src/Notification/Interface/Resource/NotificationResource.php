<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\Notification;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationResource',
    required: ['publicId', 'category', 'eventType', 'title', 'body', 'parameters', 'isRead', 'createdAt'],
    properties: [
        new OA\Property(property: 'publicId', description: 'Public notification identifier (Nanoid)', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'category', description: 'Notification category', type: 'string'),
        new OA\Property(property: 'eventType', description: 'Event type', type: 'string'),
        new OA\Property(property: 'title', description: 'Notification title', type: 'string'),
        new OA\Property(property: 'body', description: 'Notification body', type: 'string'),
        new OA\Property(property: 'parameters', description: 'Notification parameters', type: 'object', nullable: true),
        new OA\Property(property: 'isRead', description: 'Whether the notification has been read', type: 'boolean'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
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
