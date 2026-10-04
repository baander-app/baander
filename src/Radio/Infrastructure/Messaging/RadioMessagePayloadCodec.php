<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Messaging;

use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class RadioMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'radio.sync_country_stations' => ['source_id', 'country_code'],
    ];

    public function types(): array
    {
        return [
            'radio.sync_country_stations' => SyncCountryStationsCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof SyncCountryStationsCommand => [$message->getSourceId()->toString(), $message->getCountryCode()],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'radio.sync_country_stations' => new SyncCountryStationsCommand(Uuid::fromString($p['source_id']), $p['country_code']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
