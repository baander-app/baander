<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class VideoMatchRefreshMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const string TYPE = 'catalog.refresh_video_match';
    private const string INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public static function types(): array
    {
        return [self::TYPE => RefreshVideoMatchMessage::class];
    }

    public function fields(string $type): array
    {
        return $type === self::TYPE ? ['kind', 'titleId', 'operationId', 'requestedAt'] : throw new InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        if (!$message instanceof RefreshVideoMatchMessage) {
            throw new InvalidArgumentException('Unsupported message type.');
        }

        return [
            $message->kindValue(),
            $message->titleId->toString(),
            $message->operationId->toString(),
            $message->requestedAt->format(self::INSTANT_FORMAT),
        ];
    }

    public function decode(string $type, array $p): RefreshVideoMatchMessage
    {
        if ($type !== self::TYPE || !is_string($p['kind'] ?? null) || !is_string($p['titleId'] ?? null) || !is_string($p['operationId'] ?? null)) {
            throw new InvalidArgumentException('Unsupported message type or payload.');
        }
        $instant = $p['requestedAt'] ?? null;
        $requestedAt = is_string($instant) ? DateTimeImmutable::createFromFormat(self::INSTANT_FORMAT, $instant) : false;
        if ($requestedAt === false || $requestedAt->format(self::INSTANT_FORMAT) !== $instant) {
            throw new InvalidArgumentException('Invalid video match refresh instant.');
        }

        return RefreshVideoMatchMessage::fromKindValue($p['kind'], Uuid::fromString($p['titleId']), Uuid::fromString($p['operationId']), $requestedAt);
    }
}
