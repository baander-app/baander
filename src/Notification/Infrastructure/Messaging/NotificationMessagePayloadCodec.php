<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messaging;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class NotificationMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'notification.send_email' => ['user_id', 'user_email', 'category', 'title', 'body', 'created_at', 'notification_id'],
        'notification.send_push' => ['user_id', 'category', 'title', 'body', 'notification_id'],
        'notification.send_webhook' => ['user_id', 'category', 'title', 'body', 'notification_id'],
    ];

    public function types(): array
    {
        return [
            'notification.send_email' => SendEmailCommand::class,
            'notification.send_push' => SendPushCommand::class,
            'notification.send_webhook' => SendWebhookCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof SendEmailCommand => [$message->userId->toString(), $message->userEmail, $message->category->value, $message->title, $message->body, $message->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED), $message->notificationPublicId],
            $message instanceof SendPushCommand => [$message->userId->toString(), $message->category->value, $message->title, $message->body, $message->notificationPublicId],
            $message instanceof SendWebhookCommand => [$message->userId->toString(), $message->category->value, $message->title, $message->body, $message->notificationPublicId],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'notification.send_email' => new SendEmailCommand(Uuid::fromString($p['user_id']), $p['user_email'], NotificationCategory::from($p['category']), $p['title'], $p['body'], $this->decodeDate($p['created_at']), $p['notification_id']),
            'notification.send_push' => new SendPushCommand(Uuid::fromString($p['user_id']), NotificationCategory::from($p['category']), $p['title'], $p['body'], $p['notification_id']),
            'notification.send_webhook' => new SendWebhookCommand(Uuid::fromString($p['user_id']), NotificationCategory::from($p['category']), $p['title'], $p['body'], $p['notification_id']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    private function decodeDate(string $date): \DateTimeImmutable
    {
        $result = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $date);
        if ($result === false || $result->format(\DateTimeInterface::RFC3339_EXTENDED) !== $date) {
            throw new \InvalidArgumentException('Timestamp must be RFC3339 with milliseconds.');
        }
        return $result;
    }
}
