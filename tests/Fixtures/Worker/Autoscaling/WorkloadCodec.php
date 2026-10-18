<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Worker\Autoscaling;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class WorkloadCodec implements MessagePayloadCodecInterface
{
    public static function types(): array
    {
        return ['test.autoscaling_workload' => Workload::class];
    }

    public function fields(string $type): array
    {
        return $type === 'test.autoscaling_workload' ? ['batch', 'sequence', 'kind'] : throw new \InvalidArgumentException('Unknown workload.');
    }

    public function encode(object $message): array
    {
        return $message instanceof Workload ? [$message->batch, $message->sequence, $message->kind] : throw new \InvalidArgumentException('Unknown workload.');
    }

    public function decode(string $type, array $p): object
    {
        $this->fields($type);
        if (!is_string($p['batch']) || !is_int($p['sequence']) || !is_string($p['kind'])) {
            throw new \InvalidArgumentException('Invalid workload payload.');
        }
        return new Workload($p['batch'], $p['sequence'], $p['kind']);
    }
}
