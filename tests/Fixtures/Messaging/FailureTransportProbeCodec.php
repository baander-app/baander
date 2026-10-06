<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class FailureTransportProbeCodec implements MessagePayloadCodecInterface
{
    private const string TYPE = 'test.failure_transport_probe';

    public function types(): array
    {
        return [self::TYPE => FailureTransportProbe::class];
    }

    public function fields(string $type): array
    {
        return $type === self::TYPE ? ['label', 'fail'] : throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return $message instanceof FailureTransportProbe
            ? [$message->label, $message->fail]
            : throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function decode(string $type, array $p): object
    {
        if ($type !== self::TYPE || !is_string($p['label']) || !is_bool($p['fail'])) {
            throw new \InvalidArgumentException('Unsupported message type.');
        }

        return new FailureTransportProbe($p['label'], $p['fail']);
    }
}
