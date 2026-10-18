<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Worker\Autoscaling;

final readonly class Workload
{
    public function __construct(public string $batch, public int $sequence, public string $kind = 'io')
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $batch) !== 1 || $sequence < 0 || $sequence >= 48 || !in_array($kind, ['io', 'cpu'], true)) {
            throw new \InvalidArgumentException('Invalid bounded autoscaling workload.');
        }
    }
}
