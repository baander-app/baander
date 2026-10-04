<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;

final readonly class TranscodeMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'transcode.update_position' => ['session_id', 'position', 'action'],
    ];

    public function types(): array
    {
        return [
            'transcode.update_position' => UpdateTranscodePositionCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof UpdateTranscodePositionCommand => [$message->sessionId->toString(), $message->position, $message->action],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'transcode.update_position' => new UpdateTranscodePositionCommand(Uuid::fromString($p['session_id']), $p['position'], $p['action']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
