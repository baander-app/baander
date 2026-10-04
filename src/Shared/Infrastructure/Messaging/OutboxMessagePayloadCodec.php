<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;

final readonly class OutboxMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'outbox.relay' => ['batch_size'],
    ];

    public function types(): array
    {
        return [
            'outbox.relay' => RelayOutboxCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof RelayOutboxCommand => [$message->batchSize],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'outbox.relay' => new RelayOutboxCommand($p['batch_size']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
