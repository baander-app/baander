<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Domain\Model\Notification;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public notification identifier (Nanoid)',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'category' => new OA\Schema(description: 'Notification category', type: 'string'),
        'eventType' => new OA\Schema(description: 'Event type', type: 'string'),
        'title' => new OA\Schema(description: 'Notification title', type: 'string'),
        'body' => new OA\Schema(description: 'Notification body', type: 'string'),
        'parameters' => new OA\Schema(
            description: 'Notification parameters',
            type: 'object',
            nullable: true,
        ),
        'isRead' => new OA\Schema(description: 'Whether the notification has been read', type: 'boolean'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'category', 'eventType', 'title', 'body', 'parameters', 'isRead', 'createdAt'],
    component: 'NotificationResource',
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
